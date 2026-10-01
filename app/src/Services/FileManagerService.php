<?php
declare(strict_types=1);

/**
 * File operations for cPanel > File Manager, confined to one domain's site
 * folder (/var/www/<domain>): every path is resolved and checked to stay
 * inside it, symlinks are never followed out of it, and archives are
 * unpacked entry by entry (no "..", absolute paths or links; total size
 * checked against free space).
 */
final class FileManagerService
{
    public const MAX_EXTRACT = 8 * 1073741824; // 8 GB uncompressed
    public const MAX_ZIP = 4 * 1073741824;     // 4 GB into one archive

    public function __construct(private string $root)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('The site folder is missing.');
        }
        $this->root = $real;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Existing directory for a relative path (falls back to the root). */
    public function dir(string $rel): string
    {
        $t = realpath($this->root . '/' . ltrim($rel, '/'));
        return ($t !== false && is_dir($t) && $this->inside($t)) ? $t : $this->root;
    }

    public function rel(string $abs): string
    {
        return ltrim(substr($abs, strlen($this->root)), '/');
    }

    /** An entry name inside $dir (it may not exist yet); never follows a symlink out. */
    public function entry(string $dir, string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        if ($name === '' || $name === '.' || $name === '..' || preg_match('/[\x00-\x1f]/', $name)) {
            throw new InvalidArgumentException('Invalid name.');
        }
        return $dir . '/' . $name;
    }

    /** @return list<array{name:string,is_dir:bool,is_link:bool,size:int,modified:int,mode:string,perms:string}> */
    public function list(string $dir): array
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $p = "$dir/$n";
            $st = @lstat($p);
            if ($st === false) {
                continue;
            }
            $isLink = is_link($p);
            $out[] = [
                'name' => $n, 'is_dir' => !$isLink && is_dir($p), 'is_link' => $isLink,
                'size' => is_file($p) && !$isLink ? (int) $st['size'] : 0, 'modified' => (int) $st['mtime'],
                'mode' => sprintf('%03o', $st['mode'] & 0777), 'perms' => self::rwx((int) $st['mode']),
            ];
        }
        usort($out, fn($a, $b) => $b['is_dir'] <=> $a['is_dir'] ?: strcasecmp($a['name'], $b['name']));
        return $out;
    }

    public function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path) ?: throw new RuntimeException('Could not delete ' . basename($path) . '.');
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            // RecursiveDirectoryIterator doesn't descend into symlinked dirs by default; a link is just removed.
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($path) ?: throw new RuntimeException('Could not delete ' . basename($path) . '.');
    }

    /** Moves (or copies) $src into directory $destDir. */
    public function transfer(string $src, string $destDir, bool $copy): void
    {
        $dest = $destDir . '/' . basename($src);
        if (file_exists($dest) || is_link($dest)) {
            throw new RuntimeException(basename($src) . ' already exists in the destination.');
        }
        if (is_dir($src) && !is_link($src) && str_starts_with($destDir . '/', $src . '/')) {
            throw new RuntimeException("Can't put a folder inside itself.");
        }
        if (!$copy) {
            @rename($src, $dest) ?: throw new RuntimeException('Could not move ' . basename($src) . '.');
            return;
        }
        if (is_link($src)) {
            return; // links aren't copied
        }
        if (is_file($src)) {
            @copy($src, $dest) ?: throw new RuntimeException('Could not copy ' . basename($src) . '.');
            return;
        }
        @mkdir($dest, 02775);
        foreach (scandir($src) ?: [] as $n) {
            if ($n !== '.' && $n !== '..') {
                $this->transfer("$src/$n", $dest, true);
            }
        }
    }

    public function rename(string $src, string $newName): void
    {
        $dest = $this->entry(dirname($src), $newName);
        if (file_exists($dest) || is_link($dest)) {
            throw new RuntimeException("$newName already exists.");
        }
        @rename($src, $dest) ?: throw new RuntimeException('Could not rename.');
    }

    /**
     * chmod: $fileMode/$dirMode are octal strings like "644"/"755". Folders
     * keep their setgid bit (new files stay in the shared group); setuid and
     * sticky bits are never set.
     */
    public function chmod(string $path, string $fileMode, string $dirMode, bool $recursive): int
    {
        $f = self::mode($fileMode);
        $d = self::mode($dirMode);
        $n = 0;
        $apply = function (string $p) use ($f, $d, &$n): void {
            if (is_link($p)) {
                return;
            }
            $old = (int) (@fileperms($p) ?: 0);
            if (@chmod($p, is_dir($p) ? ($d | ($old & 02000)) : $f)) {
                $n++;
            }
        };
        $apply($path);
        if ($recursive && is_dir($path) && !is_link($path)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $x) {
                $apply($x->getPathname());
            }
        }
        return $n;
    }

    /** Zips $paths (relative names in $dir) into $dir/$zipName. Returns the archive path. */
    public function compress(string $dir, array $paths, string $zipName): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Zip support is missing on this server.');
        }
        $zipName = preg_replace('/\.zip$/i', '', basename($zipName)) . '.zip';
        $target = $this->entry($dir, $zipName);
        if (file_exists($target)) {
            throw new RuntimeException("$zipName already exists.");
        }
        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Could not create the archive.');
        }
        $total = 0;
        $add = function (string $abs, string $inZip) use ($zip, &$add, &$total, $target): void {
            if (is_link($abs) || $abs === $target) {
                return;
            }
            if (is_dir($abs)) {
                $zip->addEmptyDir($inZip);
                foreach (scandir($abs) ?: [] as $n) {
                    if ($n !== '.' && $n !== '..') {
                        $add("$abs/$n", "$inZip/$n");
                    }
                }
                return;
            }
            $total += (int) filesize($abs);
            if ($total > self::MAX_ZIP) {
                throw new RuntimeException('The selection is too large to zip (over 4 GB).');
            }
            $zip->addFile($abs, $inZip);
        };
        try {
            foreach ($paths as $p) {
                $add($p, basename($p));
            }
            $zip->close() ?: throw new RuntimeException('Writing the archive failed.');
        } catch (Throwable $e) {
            @$zip->close();
            @unlink($target);
            throw $e;
        }
        return $target;
    }

    /**
     * Unpacks a .zip / .tar.gz / .tgz / .tar into $destDir (existing files
     * are overwritten, like cPanel). Returns the number of files written.
     */
    public function extract(string $archive, string $destDir): int
    {
        $lower = strtolower($archive);
        $free = (int) @disk_free_space($destDir);
        if (str_ends_with($lower, '.zip')) {
            $zip = new ZipArchive();
            if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('Not a readable zip archive.');
            }
            $total = 0;
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $name = (string) $st['name'];
                $zip->getExternalAttributesIndex($i, $os, $attr);
                if ($os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException("The archive contains a link ($name) - not extracted.");
                }
                $entries[] = [$i, self::safeArchivePath($name), str_ends_with($name, '/')];
                $total += (int) $st['size'];
            }
            self::checkSize($total, $free);
            $n = 0;
            foreach ($entries as [$i, $rel, $isDir]) {
                $out = $destDir . '/' . $rel;
                if ($isDir) {
                    $this->ensureDir($out);
                    continue;
                }
                $this->ensureDir(dirname($out));
                if (is_link($out)) {
                    @unlink($out);
                }
                $in = $zip->getStream($zip->getNameIndex($i));
                $fh = $in !== false ? @fopen($out, 'wb') : false;
                if ($in === false || $fh === false) {
                    throw new RuntimeException("Could not write $rel.");
                }
                stream_copy_to_stream($in, $fh);
                fclose($fh);
                fclose($in);
                $n++;
            }
            $zip->close();
            return $n;
        }
        if (preg_match('/\.(tar\.gz|tgz|tar)$/', $lower)) {
            $z = str_ends_with($lower, '.tar') ? '' : 'z';
            // List first: refuse links, devices, absolute and ".." paths.
            $code = CpanelBackupReader::run(['tar', "-t{$z}vf", $archive], $listing);
            if ($code !== 0) {
                throw new RuntimeException('Not a readable tar archive.');
            }
            $total = 0;
            foreach (preg_split('/\r?\n/', trim((string) $listing)) as $line) {
                if ($line === '') {
                    continue;
                }
                if (!preg_match('/^([-dlhbcps])\S*\s+\S+\s+(\d+)\s+\S+\s+\S+\s+(.*)$/', $line, $m)) {
                    throw new RuntimeException('Unexpected archive listing.');
                }
                if (!in_array($m[1], ['-', 'd'], true)) {
                    throw new RuntimeException('The archive contains links or special files - not extracted.');
                }
                $rel = self::safeArchivePath($m[3]);
                // An existing link on the way (e.g. x -> /elsewhere) would be followed by tar.
                $p = $destDir;
                foreach (explode('/', $rel) as $part) {
                    $p .= '/' . $part;
                    if (is_link($p)) {
                        throw new RuntimeException("$rel would be written through the link " . $this->rel($p) . ' - not extracted.');
                    }
                }
                $total += (int) $m[2];
            }
            self::checkSize($total, $free);
            $code = CpanelBackupReader::run(['tar', "-x{$z}f", $archive, '-C', $destDir, '--no-same-owner', '--no-same-permissions', '--no-overwrite-dir'], $out);
            if ($code !== 0) {
                throw new RuntimeException('Extracting failed: ' . mb_substr(trim((string) $out), 0, 300));
            }
            return substr_count(trim((string) $listing), "\n") + 1;
        }
        throw new InvalidArgumentException('Only .zip, .tar.gz, .tgz and .tar archives can be extracted.');
    }

    public function inside(string $abs): bool
    {
        return $abs === $this->root || str_starts_with($abs, $this->root . '/');
    }

    /** mkdir -p, but only if the nearest existing parent (links resolved) is inside the site. */
    private function ensureDir(string $dir): void
    {
        $existing = $dir;
        while (!file_exists($existing) && !is_link($existing) && $existing !== dirname($existing)) {
            $existing = dirname($existing);
        }
        $r = realpath($existing);
        if ($r === false || !is_dir($r) || !$this->inside($r)) {
            throw new RuntimeException('The archive tries to write outside the folder.');
        }
        if (!is_dir($dir) && !@mkdir($dir, 02775, true)) {
            throw new RuntimeException('Could not create ' . $this->rel($dir) . '.');
        }
        $final = realpath($dir);
        if ($final === false || !$this->inside($final)) {
            throw new RuntimeException('The archive tries to write outside the folder.');
        }
    }

    private static function safeArchivePath(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || $name[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('/[\x00-\x1f]/', $name) || preg_match('/^[a-z]:/i', $name)) {
            throw new RuntimeException("Unsafe path in the archive: $name");
        }
        return rtrim($name, '/');
    }

    private static function checkSize(int $total, int $free): void
    {
        if ($total > self::MAX_EXTRACT) {
            throw new RuntimeException('The archive unpacks to more than 8 GB.');
        }
        if ($free > 0 && $total > $free - 536870912) {
            throw new RuntimeException('Not enough free disk space to unpack it.');
        }
    }

    private static function mode(string $m): int
    {
        $m = trim($m);
        if (!preg_match('/^0?[0-7]{3}$/', $m)) {
            throw new InvalidArgumentException('Permissions must be 3 octal digits, e.g. 644 or 755.');
        }
        return octdec($m) & 0777;
    }

    private static function rwx(int $mode): string
    {
        $s = '';
        foreach ([0400, 0200, 0100, 040, 020, 010, 04, 02, 01] as $i => $bit) {
            $s .= ($mode & $bit) ? 'rwx'[$i % 3] : '-';
        }
        return $s;
    }
}
