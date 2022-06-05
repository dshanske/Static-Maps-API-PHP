<?php

function request($k, $default=false) {
  return array_key_exists($k, $_REQUEST) ? $_REQUEST[$k] : $default;
}

function is_authenticated($token) {
  if( ! $token )
    return false;

  $token_file = __DIR__.'/../data/apikeys.txt';

  if(!file_exists($token_file))
    return false;

  $valid_tokens = array_filter(file($token_file));
  array_walk($valid_tokens, function(&$val) {
    $val = trim($val);
  });

  if(in_array($token, $valid_tokens))
    return true;

  return false;
}
