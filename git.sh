#!/bin/bash

read -p "Commit title: " Commit

git add . && git commit -m "$Commit" && git push
