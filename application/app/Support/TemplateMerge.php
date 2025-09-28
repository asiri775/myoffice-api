<?php
namespace App\Support;

class TemplateMerge
{
public static function render(string $html, array $data): string
{


$replacements = [];

foreach ($data as $k => $v) {
$val = (string) $v;



$replacements['{{'.$k.'}}'] = $val;
$replacements['{'.$k.'}'] = $val;
}


$verifyUrl = $data['verify_url'] ?? $data['url'] ?? null;
if ($verifyUrl) {
$button = '<a href="'.$verifyUrl.'"
    style="border-radius:3px;color:#fff;display:inline-block;text-decoration:none;background-color:#3490dc;border-top:10px solid #3490dc;border-right:18px solid #3490dc;border-bottom:10px solid #3490dc;border-left:18px solid #3490dc;">Verify
    Email Address</a>';
$replacements['[button_verify]'] = $button;
}


return strtr($html, $replacements);
}
}
