#!/bin/bash

ROOTPATH="/tmp/selenium"
BASEPATH="${ROOTPATH}/cookies"

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

find ${ROOTPATH} -name 'Temp-*' -mmin +3 -type d -exec rm -rf {} \;
find ${ROOTPATH} -name 'rust_mozprofile*' -mmin +3 -type d -exec rm -rf {} \;
find ${BASEPATH} -name 'firefox.*' -mmin +3 -type d -exec rm -rf {} \;
find ${ROOTPATH} -name '.com.google.Chrome.*' -mmin +3 -type d -exec rm -rf {} \;
find ${BASEPATH} -name 'chrome.*' -mmin +3 -type d -exec rm -rf {} \;
