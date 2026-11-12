# Staff sign-in presentation

**Continue with 8 West ID** stays the primary sign-in link. **Use a backup password**
opens the existing staff password form. It opens automatically after a failed password
submission and works without JavaScript. The existing generic error and entered email
remain visible. The form still posts to `/login.php` with its existing CSRF and safe
return-path fields. Authentication, session, rate-limit, and return-URL behavior are unchanged.

The logo supplies the brand heading, with its alt text as the fallback. There is one
footer, and the password field has no bullet placeholder. Customer portal sign-in is
unchanged; it already says **Get help with your computers.** and **Sign in with the
account your organization gave you.**

Validation includes the existing customer/staff routing HTTP cases, sign-in keyboard and
sign-out browser contracts, and the focused `signin_display_test.php` and
`signin-display.test.mjs` render/browser cases. CI's `signin-display-gallery` contains
synthetic light/dark desktop and phone views. No real account is used in these tests.
