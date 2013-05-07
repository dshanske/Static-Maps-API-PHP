<?php

$files = glob('images/*.png');
foreach($files as $f) {
  preg_match('/images\/(.+)\.png/', $f, $match);
  if($match[1] == 'powered-by-esri')
    continue;

  echo '* ![' . $match[1] . '](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/' . $match[1] . '.png) `' . $match[1] . '`' . "\n";
}
