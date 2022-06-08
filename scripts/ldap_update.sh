#!/bin/bash
#
USAGE="Usage: $(basename "$0") [-w t]
    -w t  set the wait timout t in sec (default: 30 sec)
          t=0: no wait, exit code 10
          t=-1 continue waiting with no timout"

REALCMD="$(realpath "$0")"
BASEDIR="$(dirname "$REALCMD")"/..
BASEDIR="$(realpath "$BASEDIR")"
LOCKDIR="${BASEDIR}/database/lock/`basename $0`.lock"
PIDFILE="${LOCKDIR}/PID"
PHP=/usr/bin/php
TIMEOUT=30
OPTS="$@"

while getopts "h?w:" opt; do
    case "$opt" in
    h|\?)
        echo "$USAGE"
        exit 0
        ;;
    w)
        TIMEOUT=$OPTARG
        ;;
    esac
done

shift $((OPTIND-1))

mkdir -p "${BASEDIR}/database/lock"

while true; do
    if mkdir "${LOCKDIR}" &>/dev/null; then
       trap 'status=$?; rm -rf "${LOCKDIR}"; exit $status' 0
       trap 'exit 3' 1 2 3 15
       echo "$$" >"${PIDFILE}"
       $PHP $BASEDIR/artisan lychee:LDAP_update_all_users
       exit $?
    else
        OTHERPID="$(cat "${PIDFILE}" 2>/dev/null)"
        if [ $? == 0 ]; then
            if ! kill -0 $OTHERPID &>/dev/null; then
                rm -rf "${LOCKDIR}"
            else
                if [ $TIMEOUT -ge 0 ]; then 
                    if [ $TIMEOUT -gt 0 ]; then
                        TIMEOUT=$(( TIMEOUT - 1));
                        sleep 1
                    else
                        exit 10
                    fi
                fi
            fi
        fi
    fi
done
