# Cacti Flow Service

This folder contains files for control plugin flowview services:
- flow-capture is for Linux OS with init.d
- flow-capture.sevice is for Linux OS with systemd
- flow-capture-freebsd and cacti-flow-capture are for FreeBSD system

# Features

There are two types of collectors for flowview, one requires only Cacti and the
second requires flow-tools binary package.

# Installation

## Init.d Based Systems

* First, copy the flow-capture file into /etc/init.d/

* Then, run 'chkconfig --add flow-capture

* Then, edit /etc/init.d/flow-capture and ensure the cacti_base variable is set
  properly

* Lastly, run:
  ```
  /etc/init.d/flow-capture start
  ```

This will start the service.

## SystemD Based Systems

* First, copy the file flow-capture.service into /etc/systemd/system

* Then, edit the file and ensure that the path to the flow-capture script is
  accurate

* Then, run 'systemctl daemon-reload'

* Then, run 'systemctl enable flow-capture'

* Lastly, run:
  ```
  systemctl start flow-capture
  ```

  followed by:
  ```
  systemctl status flow-capture
  ```

To verify that the service is actually running as expected.


## FreeBSD

* First, copy the cacti-flow-capture file into /usr/local/etc/rc.d

* Then, edit /etc/rc.conf and add these lines:
    cacti_flow_collector_enable="YES"
    cacti_flow_collector_args="/usr/local/share/cacti/plugins/flowview/service/flow-capture-freebsd"

* Lastly, run:
  ```
  /usr/local/etc/rc.d/cacti-flow-capture start
  ```

  followed by:
  ```
  /usr/local/etc/rc.d/cacti-flow-capture status
  ```

To verify that the service is actually running as expected.



-----------------------------------------------
Copyright (c) 2004-2024 - The Cacti Group, Inc.
