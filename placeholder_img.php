<?php
$codigo = substr(preg_replace('/[^A-Za-z0-9]/', '', $_GET['codigo'] ?? 'X'), 0, 10);
$size = min(300, max(60, (int)($_GET['size'] ?? 150)));
$hash = crc32($codigo);
$hue = abs($hash) % 360;
$bg1 = "hsl($hue, 45%, 78%)";
$bg2 = "hsl($hue, 40%, 65%)";
$fg = "hsl($hue, 35%, 30%)";

header('Content-Type: image/svg+xml');
echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="$size" height="$size" viewBox="0 0 150 150">
  <defs>
    <linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="$bg1"/>
      <stop offset="100%" stop-color="$bg2"/>
    </linearGradient>
  </defs>
  <rect width="150" height="150" fill="url(#g)" rx="10"/>
  <g transform="translate(75,60)" opacity="0.5">
    <rect x="-22" y="-18" width="44" height="36" rx="4" fill="none" stroke="$fg" stroke-width="2"/>
    <circle cx="0" cy="-2" r="10" fill="none" stroke="$fg" stroke-width="2"/>
    <circle cx="0" cy="-2" r="4" fill="$fg"/>
    <rect x="16" y="-10" width="14" height="8" rx="2" fill="$fg"/>
  </g>
  <rect x="20" y="105" width="110" height="22" rx="11" fill="$fg" opacity="0.15"/>
  <text x="75" y="120" text-anchor="middle" font-family="Arial,sans-serif" font-size="11" font-weight="bold" fill="$fg">$codigo</text>
</svg>
SVG;
