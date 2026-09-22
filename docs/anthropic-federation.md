# Shared Anthropic authentication

Safeharbor uses the [Westy credential service](https://github.com/Seckcey/8_west_westy/tree/main/federation).
Set these fields in the existing protected `ai` array on `milepost-ec2`:

```php
'provider' => 'anthropic',
'auth_mode' => 'federation',
'credential_file' => '/run/8west-westy/safeharbor/credential.json',
'credential_app' => 'safeharbor',
'credential_environment' => 'production',
```

Remove active `ai.api_key` injection and preserve the verified Claude model.
Federation rejects OpenAI overrides and ambiguous keys. Native PHP reads the
private, app-bound file as www-data per request; an expired, missing or unsafe
credential fails closed. Error bodies never echo provider tokens. Provision and
canary the host service before changing mode.

Keep ticket, billing, queue-owner and workflow authorization unchanged. Test a
bounded real answer, user/tenant denials and refresh beyond initial expiry; do
not mutate tickets or invoices just to prove authentication. Record release
and protected configuration identity. Retain the previous image/configuration
together for rollback and inventory other key consumers before revocation.

Native PHP and the AWS role share the host trust boundary. Different app
accounts provide attribution/revocation, not sibling isolation. A code merge
does not prove production federation activation.
