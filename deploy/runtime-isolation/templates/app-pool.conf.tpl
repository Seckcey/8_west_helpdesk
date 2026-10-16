; GENERATED OFFLINE CANDIDATE for {{APP}} - not installed, not runtime evidence. See facts.json.
; Input digest: {{INPUT_SHA256}}
;
; One dedicated root FPM master loads ONLY this file (separate process, opcache and PrivateTmp lifetime).
; Start that master with empty opcache.preload and opcache.file_cache so no application code runs as root.
; Workers drop to {{APP_USER}}:{{APP_GROUP}}; the frontend {{FRONTEND_USER}} reaches them only through the
; root:{{FRONTEND_GROUP}} 0660 socket below. Socket ACL listen options are deliberately absent because they
; override the owner/group/mode controls. There is no TCP listener.

[global]
pid = {{SOCKET_DIR}}/fpm.pid
error_log = /proc/self/fd/2
daemonize = no
; WARNING: 0 is explicit and is NOT protection. With 0 a graceful stop does not wait for busy children:
; FPM escalates to terminating them immediately. No finite value is protection either. Only the cold window's
; rule protects accepted work: one bound QUIT is sent only to an original master that is already idle (no
; children, no connected or queued requests, producers closed).
process_control_timeout = 0

[{{APP}}]
user = {{APP_USER}}
group = {{APP_GROUP}}
listen = {{SOCKET}}
listen.owner = root
listen.group = {{FRONTEND_GROUP}}
listen.mode = 0660

pm = ondemand
pm.max_children = {{MAX_CHILDREN}}
pm.process_idle_timeout = 20s
pm.max_requests = 500

clear_env = yes
catch_workers_output = yes
chdir = {{APP_ROOT}}
security.limit_extensions = .php

; Private per-app session and temporary storage, owned {{APP_USER}}:{{APP_GROUP}} 0700.
env[TMPDIR] = {{TMP_DIR}}
php_admin_value[session.save_path] = {{SESSION_DIR}}
php_admin_value[upload_tmp_dir] = {{TMP_DIR}}
php_admin_value[sys_temp_dir] = {{TMP_DIR}}
php_admin_value[cgi.fix_pathinfo] = 0
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /proc/self/fd/2
