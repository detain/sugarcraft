#!/bin/bash
base="$(readlink -f "$(dirname "$0")")";
clear;
for i in $(ls $base/../*.ansi); do
  i="$(basename "${i}" .ansi)"
  wh="$(echo "${i}"|sed s#"^.*-\([0-9]*\)x\([0-9]*\)$"#"\1 \2"#g)"
  w="$(echo "${wh}"|cut -d" " -f1)"
  h="$(echo "${wh}"|cut -d" " -f2)"
  echo -e "\n$i\n";
  s=$(date +%s);
  anim=1
  if [ "$(echo "$i"|grep -- "-anim-")" = "" ]; then
    # non ansi
    asciinema rec --command "cat ${base}/../${i}.ansi; sleep 0.5s" --title ${i} --cols $w --rows $(($h + 1)) --overwrite "${base}/../${i}.cast"
    anim=0
  else
    # ansi
    asciinema rec --command "cat ${base}/../${i}.ansi | pv --quiet --rate-limit  100000" --title ${i} --cols $w --rows $(($h + 1)) --overwrite "${base}/../${i}.cast"
  fi
  echo "Converting screencast to gif"
  agg -v "${base}/../${i}.cast" "${base}/../${i}.gif" && rm -f "${base}/../${i}.cast"
  if [ $anim -eq 0 ]; then
    convert "${base}/../${i}.gif[1]" -coalesce "${base}/../${i}.png" && rm -f "${base}/../${i}.gif"
  fi
  e=$(date +%s);
  echo
  t=$(echo "$e - $s"|bc)
  echo "finished in $t seconds";
done
