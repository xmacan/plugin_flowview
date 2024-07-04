# flowview

This plugin allows you to see reports based off the data in your Netflow flows.

# Features

* Customizable Scheduled Reports.
* Ability to view Flow Data in Table Form or three Chart Forms.
* Parallel Query Execution to increase the speed of report generation.
* Support for Shard Query and Horizontal Scaling through use of technologies 
  such as MariaDB MaxScale.
* Tracking of IP addresses that do not resolve through DNS by using ARIN's
  whois service.

# Installation

To install the plugin, simply copy the plugin_flowview directory to Cacti's plugins
directory and rename it to simply 'flowview'.

Copy config.php.dist to config.php and set correct db connection.

Once this is complete, go to Cacti's Plugin Management section, and Install 
and Enable the plugin.

There are a number of settings that are important that you can find under
Console > Configuration > Settings > Flowview.

The minimum version of Cacti required for this plugin will be Cacti 1.2.27.
However, you will have to install the patch to Cacti's database API in this
commit: c11405e584c012f675fb420acf78bcecc7d02d9f.

If you are running an older version of Cacti, you may run Flowview, but you
will not be able to leverage the MaxScale Shard Query feature.

## Required:

Before you start, with this version of Flowview, you have to ensure that you are
at MariaDB 10.0.12+.  Cacti has been shown to support MariaDB upto 14.x. MySQL
5.6+ is required, and it has been reported that Cacti work with MySQL 8.x, but
this MySQL release was a major paradigm shift for Oracle.  So, if you go there,
be prepared for some extra love/hate in your relationship, but many of the
changes are very welcome.

Then, Install flowview just like any other plugin, just copy it into the plugin
directory, and Use Console -> Plugin Management to Install and Enable.

This version of the flowview plugin no longer requires flowtools or
OpenFlashCharts.  In fact the entire import and reporting process is handled
through php and uses JavaScript based charting already available in Cacti.

Note that additionally, you must install the linux utility `netstat` if it is
not already installed.  Netstat will help Cacti determine if the `flow-capture`
service in question is actually running.

After installing, you should set your partitioning and retention settings from
Console > Configuration > Settings > Flowview.  There is a flowview section there
that you can customize.

Next you have to setup your Cacti server as a Flowview sink from your various
sources.  Then, from Flowview -> Listeners, you must add the various listeners
for all your flow-capture sources.  It's critical that you specify the correct
port, and if there is to be any filtering, having a value other than 0 for the
allowed devices.

You must then setup the init.d or systemd service to receive captured data and
transfer into the Cacti database.  Check the README.md in the service folder to
describe this process.  Any time you add a new listener, you must restart this
service.

## Automatic Flow Version Detection:

The new Cacti based flow-capture script will auto-detect either V5, V9 or IPFIX
flows automatically.  So, can dynamically switch these streams versions without
issue and you can have multiple streams coming into the same port at the same
time.  However, we recommend you have you watch the CPU utilization of the 
flow collector processes, and if they start consuming near 100% utilization,
you should add more listeners to spread the load.

## Automatic Domain Resolution:

The flow-capture script will receive the flow data, and attempt to resolve the
domain names of the flows.  In the case that an IP Address does not properly
resolve to a domain, Cacti automatically queries IANA to find the owner and
assigned as 'assumed' domain for those flows.  If your Cacti server can not
reach IANA, then those IP's will simply not be resolved.

## Upgrading from Prior Versions

Since the current release does not leverage flow-capture, you will need to
migrate your existing flow data into the Cacti database.  Before you perform
this migration, ensure that your Cacti system, has enough space to handle all
the flow data.  You should check the size of your existing flows, and then
verify that you have enough space to handle the data inside of a MySQL database.

Once you have done this, simply run the 'import_flows.php' script and all your
legacy flow data will be imported.  Remember, it's important that you define
your partitioning scheme ahead of time, especially if you have large quantities
of flow data streaming into the Cacti server.

# Possible Bugs?

If you figure out this problem, goto GitHub and create a pulls request or open
an issue.

# Future Changes

Got any ideas or complaints, please see the Cacti forums or GitHub for a
resolution.

-----------------------------------------------
Copyright (c) 2004-2024 - The Cacti Group, Inc.
