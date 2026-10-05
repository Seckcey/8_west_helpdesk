"""Opt-in real Apache closure proof using one disposable Coastline container.

Set TENANT_AI_APACHE_TEST_IMAGE to an existing immutable Apache/PHP CLI image.
No image pull, host port, production mount, default image entrypoint or live
Apache configuration is used. The controller runs on the authorized test host.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import unittest
import uuid
import re
from unittest.mock import patch

import tenant_ai_freeze as freeze


IMAGE=os.environ.get('TENANT_AI_APACHE_TEST_IMAGE','')
DOCROOT='/srv/8west/apps/safeharbor/current/public'


@unittest.skipUnless(IMAGE.startswith('sha256:') and shutil.which('docker'),
                     'explicit existing Coastline Apache fixture image required')
class ApacheClosureTests(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory(prefix='tenant-ai-apache-')
        self.addCleanup(self.temporary.cleanup)
        self.root=Path(self.temporary.name);self.root.chmod(0o755)
        self.name='tenant-ai-apache-'+uuid.uuid4().hex[:12]
        self.addCleanup(self.cleanup_container)
        for name in ('app/public/portal','app/public/assets','icons','other'):
            (self.root/name).mkdir(parents=True,exist_ok=True)
        for name in ('app/public/index.php','app/public/login.php','app/public/portal/index.php',
                     'app/public/assets/probe.txt','icons/probe.txt','other/index.html'):
            (self.root/name).write_text('synthetic fixture only\n')
        # A late .htaccess If can override directory/location authorization.
        # The frozen root must therefore also disable per-directory overrides.
        (self.root/'app/public/portal/.htaccess').write_text('<If "true">\nRequire all granted\n</If>\n')

    def docker(self,*arguments,check=True):
        return subprocess.run(['docker',*arguments],capture_output=True,text=True,timeout=30,check=check)

    def cleanup_container(self):
        found=self.docker('ps','-aq','--filter','name=^/'+self.name+'$').stdout.strip()
        if found:
            owner=self.docker('inspect','--format','{{index .Config.Labels "com.8west.apache-fixture"}}',self.name).stdout.strip()
            self.assertEqual(owner,self.name)
            self.docker('rm','--force',self.name)
        self.assertFalse(self.docker('ps','-aq','--filter','name=^/'+self.name+'$').stdout.strip())

    def configuration(self,vhost):
        # Only test transport substitutions: the authorization/cache/header
        # directives are the actual reviewed template or its frozen output.
        site=vhost.decode().replace(DOCROOT,'/fixture/app/public')
        site=site.replace('<IfModule mod_ssl.c>\n','').replace('</IfModule>\n','')
        site=site.replace('<VirtualHost *:443>','<VirtualHost *:8080>')
        site='\n'.join(line for line in site.splitlines()
                       if not line.strip().startswith(('SSLCertificateFile ','SSLCertificateKeyFile ','Include /etc/letsencrypt/')))+'\n'
        site=site.replace('${APACHE_LOG_DIR}','/tmp')
        modules=('mpm_prefork','authz_core','authz_host','alias','headers','expires','filter','deflate','mime','dir','status')
        prelude='ServerRoot /etc/apache2\nListen 127.0.0.1:8080\nServerName fixture.invalid\n'
        prelude+=''.join(f'LoadModule {name}_module /usr/lib/apache2/modules/mod_{name}.so\n' for name in modules)
        prelude+='''User www-data
Group www-data
PidFile /tmp/apache-fixture.pid
ErrorLog /tmp/apache-fixture-error.log
LogFormat "%h %>s" combined
TypesConfig /etc/mime.types
StartServers 1
MinSpareServers 1
MaxSpareServers 1
ServerLimit 2
MaxRequestWorkers 2
<Directory />
    AllowOverride None
    Require all denied
</Directory>
Alias /icons/ /fixture/icons/
<Directory /fixture/icons>
    AllowOverride None
    Require all granted
</Directory>
<Location /server-status>
    SetHandler server-status
    Require local
</Location>
'''
        other='''<VirtualHost *:8080>
    ServerName unrelated.example
    DocumentRoot /fixture/other
    <Directory /fixture/other>
        AllowOverride None
        Require all granted
    </Directory>
</VirtualHost>
'''
        return prelude+site+other

    def request(self,host,path,method='GET'):
        code='''$c=stream_context_create(['http'=>['method'=>$argv[3],'header'=>'Host: '.$argv[1]."\\r\\n",'ignore_errors'=>true,'timeout'=>2]]);
$b=file_get_contents('http://127.0.0.1:8080'.$argv[2],false,$c);
if (!isset($http_response_header[0]) || !preg_match('/^HTTP\\/[^ ]+ ([0-9]{3})/',$http_response_header[0],$m)) exit(2);
echo json_encode(['status'=>(int)$m[1],'body'=>$b]);'''
        return json.loads(self.docker('exec',self.name,'php','-r',code,host,path,method).stdout)

    def start(self,vhost):
        self.start_configuration(self.configuration(vhost))

    def start_configuration(self,configuration):
        self.cleanup_container()
        (self.root/'httpd.conf').write_text(configuration)
        self.docker('run','-d','--name',self.name,'--label','com.8west.apache-fixture='+self.name,
                    '--network','none','--memory','128m','--memory-swap','128m','--cpus','.25',
                    '--pids-limit','64','--read-only','--cap-drop','ALL',
                    '--cap-add','SETUID','--cap-add','SETGID','--cap-add','KILL',
                    '--tmpfs','/tmp:rw,nosuid,nodev,size=16m',
                    '--tmpfs','/var/www/html:rw,nosuid,nodev,size=1m',
                    '--mount',f'type=bind,src={self.root},dst=/fixture,readonly',
                    '--entrypoint','/usr/sbin/apache2',IMAGE,'-f','/fixture/httpd.conf','-DFOREGROUND')
        deadline=time.monotonic()+15
        while time.monotonic()<deadline:
            try:
                self.request('safeharbor.8westit.com','/login.php')
                return
            except (subprocess.CalledProcessError,json.JSONDecodeError):
                time.sleep(.1)
        self.fail('isolated Apache did not start: '+self.docker('logs',self.name,check=False).stderr)

    def paired_configuration(self,https,http):
        config=self.configuration(https)
        config=config.replace('Listen 127.0.0.1:8080\n','Listen 127.0.0.1:8080\nListen 127.0.0.1:8443\n')
        config=config.replace('User www-data\n','LoadModule ssl_module /usr/lib/apache2/modules/mod_ssl.so\n'
                              'LoadModule rewrite_module /usr/lib/apache2/modules/mod_rewrite.so\nUser www-data\n')
        config=config.replace('<VirtualHost *:8080>','<VirtualHost *:8443>\nSSLEngine On\n'
                              'SSLCertificateFile /fixture/server.crt\nSSLCertificateKeyFile /fixture/server.key',1)
        plain=re.search(r'<VirtualHost \*:80>.*?</VirtualHost>',self.configuration(http),re.S).group(0)
        plain=plain.replace('<VirtualHost *:80>','<VirtualHost *:8080>')
        # Put the reviewed HTTP app before the unrelated HTTP vhost.
        return config.replace('<VirtualHost *:8080>\n    ServerName unrelated.example',
                              plain+'\n<VirtualHost *:8080>\n    ServerName unrelated.example')

    def tls_probe(self,args,check=True):
        # Test-only transport/CA substitutions. The production curl command and
        # its URL-derived TLS identity/Host headers remain otherwise unchanged.
        extra=['--cacert','/fixture/server.crt']
        for host in ('safeharbor.8westit.com','www.safeharbor.8westit.com'):
            extra+=['--connect-to',host+':443:127.0.0.1:8443','--connect-to',host+':80:127.0.0.1:8080']
        result=self.docker('exec',self.name,*args[:-1],*extra,args[-1],check=check)
        return result.stdout.strip() if check else result

    def test_real_tls_prerequisites_and_both_vhosts_alias_closure(self):
        # A synthetic trusted certificate covers canonical only, matching the
        # actual SAN contract. No production cert/key or TLS override is used.
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
                        '-subj','/CN=safeharbor.8westit.com','-addext','subjectAltName=DNS:safeharbor.8westit.com',
                        '-keyout',str(self.root/'server.key'),'-out',str(self.root/'server.crt')],
                       check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=30)
        (self.root/'server.key').chmod(0o644)  # Disposable fixture only; mount is read-only.
        https=Path(__file__).with_name('apache-safeharbor-le-ssl.conf').read_bytes()
        http=Path(__file__).with_name('apache-safeharbor.conf').read_bytes()
        frozen_https=freeze.frozen_vhost(https,'safeharbor.8westit.com',DOCROOT)
        frozen_http=freeze.frozen_vhost(http,'safeharbor.8westit.com',DOCROOT)
        profile={'app':'safeharbor','hostnames':['safeharbor.8westit.com','www.safeharbor.8westit.com'],
                 'probe_tls_hostname':'safeharbor.8westit.com','probe_paths':['/login.php','/portal/'],
                 'vhost':{},'additional_vhosts':[{}]}
        self.start_configuration(self.paired_configuration(https,http))
        self.assertEqual(self.request('www.safeharbor.8westit.com','/login.php')['status'],200)
        bad=self.tls_probe(['curl','-q','--noproxy','*','--silent','--output','/dev/null',
                            'https://www.safeharbor.8westit.com/login.php'],check=False)
        self.assertEqual(bad.returncode,60)  # SAN mismatch is still refused.
        with patch.object(freeze,'checked_command',side_effect=self.tls_probe):
            freeze.probe_origin(profile)
        self.start_configuration(self.paired_configuration(frozen_https,http))
        self.assertEqual(self.request('www.safeharbor.8westit.com','/login.php')['status'],200)
        with patch.object(freeze,'checked_command',side_effect=self.tls_probe):
            with self.assertRaisesRegex(RuntimeError,'closure not observed'):freeze.probe_origin(profile,closed=True)
        self.start_configuration(self.paired_configuration(frozen_https,frozen_http))
        with patch.object(freeze,'checked_command',side_effect=self.tls_probe):
            freeze.probe_origin(profile,closed=True)
        self.assertEqual(self.request('www.safeharbor.8westit.com','/login.php')['status'],403)
        self.assertEqual(self.request('unrelated.example','/')['status'],200)
        self.start_configuration(self.paired_configuration(https,http))
        with patch.object(freeze,'checked_command',side_effect=self.tls_probe):freeze.probe_origin(profile)
        self.assertEqual(self.request('www.safeharbor.8westit.com','/login.php')['status'],200)

    def test_actual_shape_closes_root_assets_overrides_and_inherited_aliases(self):
        original=Path(__file__).with_name('apache-safeharbor-le-ssl.conf').read_bytes()
        closed=freeze.frozen_vhost(original,'safeharbor.8westit.com',DOCROOT)
        paths=('/','/login.php','/portal/','/assets/probe.txt','/icons/probe.txt','/server-status')
        self.start(original)
        for path in paths:self.assertEqual(self.request('safeharbor.8westit.com',path)['status'],200,path)
        # Show why merely making the old root substitution is insufficient.
        self.start(original.replace(b'Require all granted',b'Require all denied'))
        for path in ('/portal/','/icons/probe.txt','/server-status'):
            self.assertEqual(self.request('safeharbor.8westit.com',path)['status'],200,path)
        self.start(closed)
        for host in ('safeharbor.8westit.com','www.safeharbor.8westit.com'):
            for path in paths:
                for method in ('GET','HEAD','POST'):
                    self.assertEqual(self.request(host,path,method)['status'],403,(host,path,method))
        self.assertEqual(self.request('unrelated.example','/')['status'],200)
        self.assertEqual(self.request('unrelated.example','/icons/probe.txt')['status'],200)
        self.start(original)
        for path in paths:self.assertEqual(self.request('safeharbor.8westit.com',path)['status'],200,path)


if __name__=='__main__':unittest.main()
