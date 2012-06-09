#!/bin/bash

if [ -e tmp/restart.txt ]
then
  rm tmp/restart.txt
else
  touch tmp/restart.txt
fi
