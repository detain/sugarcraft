#!/bin/bash
base="$(readlink -f "$(dirname "$0")")";
clear;
for i in $(ls $base/../*-anim-tc-*|grep -v skinny-anim);do
  echo -e "\n$i\n";
  s=$(date +%s);
  cat $i  | pv --quiet --rate-limit 100000; e=$(date +%s);
  echo "$e - $s"|bc -l;
done
