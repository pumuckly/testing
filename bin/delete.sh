#!/bin/bash

BASEPATH="/tmp/selenium/cookies"

if [ -d ${BASEPATH} ]; then
    for entry in "${BASEPATH}"/*.delete.flag
    do
        NAME=`echo "$entry" | sed 's/\.delete\.flag$//'`
        if [ -d "${NAME}" ]; then
            rm -r ${NAME}
        fi
        if [ -e "${entry}" ]; then
            unlink ${entry}
        fi
    done
fi

find /tmp/selenium -name 'rust_mozprofile*.*' -mmin +5 -type d -exec rm -rdf {} \;
find /tmp/selenium -name 'Temp-*.*' -mmin +5 -type d -exec rm -rdf {} \;
