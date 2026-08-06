echo "=== mu-plugins dir ==="
ls -la /storage/wordpress/wp-content/mu-plugins/ 2>&1
echo "=== PHP files in uploads (webshell check) ==="
find /storage/wordpress/wp-content/uploads -iname "*.php*" 2>&1
echo "=== plugins dir listing ==="
ls -la /storage/wordpress/wp-content/plugins/
echo "=== files modified in last 20 days across wp-content (excluding uploads/cache) ==="
find /storage/wordpress/wp-content -type f -mtime -20 -not -path "*/uploads/*" -not -path "*/cache/*" -printf "%TY-%Tm-%Td %TH:%TM  %p\n" | sort
echo "=== theme dir listing ==="
find /storage/wordpress/wp-content/themes/jackbrain -type f -printf "%TY-%Tm-%Td %TH:%TM  %p\n" | sort