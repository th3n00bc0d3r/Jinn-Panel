# Provisioning API

JinnPanel has an HTTP API for creating and managing hosting accounts from a
billing system, an order form or a script. It is a **WHM API 1 compatible
subset**: the URL scheme, the `Authorization: whm` header and the response
envelope are WHM's, so code written for cPanel/WHM's `createacct` (WHMCS-style
modules, custom order forms) works against JinnPanel by changing the host name
and the token.

## Tokens

Create tokens in **WHM > API Tokens** (administrators and resellers).

- A token acts with **its owner's rights**. An administrator's token sees every
  hosting account and package; a reseller's token sees only that reseller's
  accounts and the packages it may hand out, and its package's account limit
  applies.
- The token is shown **once**. Only its SHA-256 hash is stored.
- A token can be limited to a list of client IP addresses.
- Make one token per site or tool, so you can revoke them one at a time.
  Deleting a token takes effect immediately.
- Every action taken through the API is written to the **Activity Log**
  (`api.createacct`, `api.suspendacct`, ...), under the token's owner.
- Failed authentication is throttled per client address: after 5 failures in
  15 minutes that address is refused (HTTP 429) for the rest of the window.

## Requests

```
https://<panel host>/json-api/<function>?api.version=1&<parameters>
```

- Only on the panel's own host name (`panel.<server hostname>`), over HTTPS.
- Parameters in the query string or as a form-encoded POST body
  (`application/x-www-form-urlencoded`). Prefer POST for `createacct`, so the
  password isn't in access logs.
- Authenticate with either header:

```
Authorization: whm <owner username>:<token>
Authorization: Bearer <token>
```

## Responses

Always JSON, always this envelope:

```json
{
  "metadata": { "version": 1, "command": "createacct", "result": 1, "reason": "Account jdoe created." },
  "data": { ... }
}
```

`metadata.result` is `1` on success and `0` on failure, with the reason in
`metadata.reason`. Failures also use an HTTP status: 400 bad input,
401 bad token, 404 unknown function/account, 409 domain already hosted,
429 throttled, 500 internal error (details in the panel's `app.log`).

## Functions

| Function | Parameters | Does |
|---|---|---|
| `version` | - | `data.version` (API version), `data.product`. A cheap way to test a token. |
| `applist` | - | `data.app`: the functions below. |
| `listpkgs` | - | `data.pkg[]`: packages this token may use - `id`, `name`, `QUOTA` (MB), `BWLIMIT` (MB/month), `MAXADDON` (domains), `MAXSQL`, `MAXPOP`, `MAXFTP`, `global`. |
| `listaccts` | `search` (optional; username, email or domain contains) | `data.acct[]`: hosting accounts this token may manage. |
| `accountsummary` | `user` | `data.acct[0]`: one account. |
| `createacct` | `username`, `domain`, `plan`, `contactemail`, `password`, `contactname` (optional) | Creates the hosting account **and** its first domain (vhost, DNS zone, folder, PHP pool, certificate), all or nothing. |
| `changepackage` | `user`, `pkg` (package name or id) | Moves the account to another package; its limits change at once. |
| `suspendacct` | `user`, `reason` (optional; included in the email to the customer) | Suspends: sites answer 503, mail/SFTP/MySQL logins and cron are switched off. |
| `unsuspendacct` | `user` | Turns everything back on. |
| `removeacct` | `user` | Deletes the account and everything it owns. **Cannot be undone.** |

An account in `data.acct` looks like:

```json
{ "user": "jdoe", "domain": "example.com", "email": "jdoe@example.org", "plan": "Starter",
  "suspended": 0, "status": "active", "unix_startdate": 1763900000,
  "startdate": "2026-11-23 12:13:20", "owner": null }
```

### createacct

- `username`: lowercase letters and digits, starting with a letter; reserved
  and existing system names are refused.
- `plan`: a package **name** (case-insensitive) or id, from `listpkgs`.
- `password`: at least 10 characters, not a common password, not the username.
- `domain`: checked before anything is created - the server's own names,
  public suffixes, names inside another account's domain and domains already
  hosted are refused.

On success `data` also has `login_url` (`https://<domain>:2083/`),
`panel_url`, `nameservers` (point the domain at these, or an A record at the
server), `dns` (whether the zone was created) and `ssl_mode`.

The site gets a self-signed certificate until its DNS points at the server,
then switches to Let's Encrypt by itself.

## Examples

```sh
TOKEN=...   # from WHM > API Tokens
PANEL=https://panel.server.example.com

# Is the token good?
curl -s -H "Authorization: whm admin:$TOKEN" "$PANEL/json-api/version?api.version=1"

# Packages
curl -s -H "Authorization: whm admin:$TOKEN" "$PANEL/json-api/listpkgs?api.version=1"

# Create an account (POST, so the password stays out of logs)
curl -s -H "Authorization: whm admin:$TOKEN" "$PANEL/json-api/createacct?api.version=1" \
  --data-urlencode username=jdoe --data-urlencode domain=example.com \
  --data-urlencode plan=Starter --data-urlencode contactemail=jdoe@example.org \
  --data-urlencode 'password=a-long-random-password'

# Suspend / unsuspend / remove
curl -s -H "Authorization: whm admin:$TOKEN" "$PANEL/json-api/suspendacct?api.version=1" --data user=jdoe --data-urlencode 'reason=Invoice overdue'
curl -s -H "Authorization: whm admin:$TOKEN" "$PANEL/json-api/unsuspendacct?api.version=1" --data user=jdoe
curl -s -H "Authorization: whm admin:$TOKEN" "$PANEL/json-api/removeacct?api.version=1" --data user=jdoe
```

PHP, with nothing but cURL:

```php
function jinnpanel(string $fn, array $params = []): array
{
    $ch = curl_init("https://panel.server.example.com/json-api/$fn?api.version=1");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_HTTPHEADER => ['Authorization: whm admin:' . getenv('JINNPANEL_TOKEN')],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $res = json_decode((string) curl_exec($ch), true) ?? [];
    if (($res['metadata']['result'] ?? 0) !== 1) {
        throw new RuntimeException($res['metadata']['reason'] ?? 'no answer from JinnPanel');
    }
    return $res['data'];
}
```

## Differences from WHM

- Only the functions above. Others answer 404 with the list of what exists.
- `api.version` is accepted and ignored; answers are always API 1 shaped.
- No `api.version=0` (XML-API) output, no `cpsess` session URLs, no
  `/execute/` UAPI.
- Account creation does not email the customer: the caller sends its own
  welcome message (JinnPanel's own welcome email is offered when an account is
  created from WHM > Accounts).
