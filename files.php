<?php

$files = glob('images/*.png');
foreach($files as $f) {
  preg_match('/images\/(.+)/', $f, $match);
  echo '* `' . $match[1] . '` [' . $match[1] . '](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/' . $match[1] . ')' . "\n";
}
