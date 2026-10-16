# GENERATED OFFLINE CANDIDATE for {{APP}} - an insert for the reviewed vhost(s), not a complete vhost.
# Input digest: {{INPUT_SHA256}}
#
# Keep the existing MPM, routing, rewrites, Authorization handling and headers. This adds only the
# proxy_fcgi handler for the one PHP DocumentRoot and PHP-source denial for static/proxy-only roots.
# mod_php must be disabled for the whole frontend before this is meaningful: any PHP still running as
# {{FRONTEND_USER}} can connect to every app socket.
#
# Accounted handlers:
{{HANDLER_LINES}}

# Application code beside the DocumentRoot is never web-served.
<Directory "{{APP_ROOT}}">
    Require all denied
</Directory>

# The only PHP DocumentRoot for {{APP}}.
<Directory "{{DOCUMENT_ROOT}}">
    Require all granted
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:{{SOCKET}}|fcgi://{{APP}}-fpm/"
    </FilesMatch>
    # A request must never set PHP ini values through FastCGI parameters.
    ProxyFCGISetEnvIf "true" !PHP_VALUE
    ProxyFCGISetEnvIf "true" !PHP_ADMIN_VALUE
</Directory>
ProxyFCGIBackendType FPM

# Static/proxy-only roots: removing a handler alone would serve PHP source, so deny it.
{{DENY_SECTIONS}}
