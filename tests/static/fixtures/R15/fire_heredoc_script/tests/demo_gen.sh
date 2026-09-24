#!/bin/bash
# 가짜 견본: heredoc 으로 만든 스크립트 안에서 비밀이 인자로 간다
WORK=$(mktemp -d)
cat > "$WORK/helper.sh" <<EOF
#!/bin/sh
s=\$(cat /srv/demo/totp-secret.txt)
exec php calc.php "\$s"
EOF
chmod +x "$WORK/helper.sh"
