"""Execute the documented Bash/Zsh blocks with disposable keys and a fake gh only."""
from pathlib import Path
import json, os, re, stat, subprocess, tempfile

root = Path(__file__).resolve().parents[2]
blocks = re.findall(r'```bash\n(.*?)\n```', (root / 'docs/GITHUB-UPDATES-BETRIEB.md').read_text(), re.S)
assert len(blocks) == 3
for shell in ('bash', 'zsh'):
    with tempfile.TemporaryDirectory(prefix='swsl-operator-') as tmp:
        temp = Path(tmp)
        plugin, keys, fake = temp/'plugin', temp/'keys', temp/'bin'
        ring = plugin/'src/Update/Config/trusted-keys.php'
        ring.parent.mkdir(parents=True)
        ring.write_text("<?php return ['old' => '" + 'A'*43 + "='];\n")
        fake.mkdir()
        # No network tool is ever invoked. Assert private material arrives solely via stdin.
        gh = fake/'gh'
        gh.write_text('''#!/usr/bin/env python3
import os, sys
from pathlib import Path
args=sys.argv[1:]
assert args in [
 ['auth','status'],
 ['secret','set','SW_PLUGIN_RELEASE_SIGNING_KEY','--repo','sw-wordpress/sw-securelogin','--env','plugin-releases'],
 ['variable','set','SW_PLUGIN_RELEASE_KEY_ID','--repo','sw-wordpress/sw-securelogin','--env','plugin-releases','--body','sw-securelogin-2026-11'],
 ['secret','list','--repo','sw-wordpress/sw-securelogin','--env','plugin-releases'],
 ['variable','list','--repo','sw-wordpress/sw-securelogin','--env','plugin-releases']]
if args[:2]==['secret','set']:
 assert sys.stdin.read()==(Path(os.environ['SWSL_DISPOSABLE_KEYS'])/'signing-key.base64').read_text()
with open(os.environ['SWSL_GH_LOG'],'a') as f: f.write(args[0]+' '+args[1]+'\\n')
''')
        gh.chmod(0o700)
        subprocess.run(['git','init','-q',str(plugin)],check=True)
        adapted=[b.replace('$HOME/.local/share/sw-securelogin-release-keys',str(keys)).replace('/var/www/html/wordpress_dev/wp-content/plugins/sw-securelogin',str(plugin)) for b in blocks]
        env={**os.environ,'PATH':str(fake)+os.pathsep+os.environ['PATH'],'SWSL_DISPOSABLE_KEYS':str(keys),'SWSL_GH_LOG':str(temp/'gh.log')}
        def run(index, ok=True):
            result=subprocess.run([shell,'-c',adapted[index]],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            assert (result.returncode==0)==ok, f'{shell}: block {index+1} unexpected exit (output withheld)'
            if keys.exists():
                secret=(keys/'signing-key.base64').read_bytes().strip()
                assert secret not in result.stdout+result.stderr
        run(0)
        assert stat.S_IMODE(keys.stat().st_mode)==0o700
        for f in keys.iterdir(): assert stat.S_IMODE(f.stat().st_mode)==0o600
        before=(keys/'signing-key.base64').read_bytes()
        run(0,False)
        assert (keys/'signing-key.base64').read_bytes()==before
        run(1)
        check=subprocess.check_output(['php','-r','$a=require $argv[1];echo json_encode($a);',str(ring)])
        data=json.loads(check)
        assert list(data)==['old','sw-securelogin-2026-11']
        assert before.strip() not in ring.read_bytes()
        run(1) # identical ID/key remains valid
        saved=ring.read_bytes()
        incoming=keys/'public-keys.php'
        incoming.write_text("<?php return ['sw-securelogin-2026-11' => '"+'B'*43+"='];\n")
        run(1,False)
        assert ring.read_bytes()==saved
        run(2)
        assert (temp/'gh.log').read_text().splitlines()==['auth status','secret set','variable set','secret list','variable list']
print('PASS: all three documented blocks, Bash/Zsh, 0700/0600, overwrite/conflict refusal, public-key preservation, secret via stdin (fake gh only).')
