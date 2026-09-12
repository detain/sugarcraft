These are the graphs that make up the Performance :: Dashboard in MySQL WorkBench's Administration tab

There are 3 groupings of data found below, nested under them each is one of the graphs.. and nested below them is details about the graphs.. 
The graphs updae live refreshed every second moving the graph data along giving it that live feel

- Network Status
 - incoming netwrok traffic (bytes/s)
  - line graph
  - receiving xx kb/s label
 - outgoing network traffic bytes/s
  - line graph
  - sending xx kb/s label
 - client connections total
  - line graph
  - bar on right showing current vs limit
- mysql status
 - table open cache
  - donut graph
  - efficiency %
 - sql statements executed (#)
  - mult line graph
  - select,insert,update,delete,create,alter,drop x/s labels
- innodb status
 - innodb buffer pool
  - donut graph
  - read reqs x pages/s label
  - write reqs xx pages/s label
  - disk reads xx #/s label
  - usage %
 - innodb disk writes
  - line graph
  - data written xx kb/s lable
  - writes xx #/s label
  - writing xxx kb/s label
 - innodb disk reads
  - line graph
  - doublewrite buffer writes xx/s lable
  - reading xx b/s label



These are the graphs and parts that make up the Management :: Server Status section in MySQL WorkBench's Administration tab

Server status
 - simple running label w/ a play button icon above it, probably a stopped message when its not running
CPU/Load
 - vertical progress type bar
 - cpu load label
connections
 - filled in line graph
 - number of connections
traffic
 - filled in line graph
 - x.xx kb/s label
key efficiency
 - stacked line graph
 - %
selects per second
 - stacked line graph
 - count
innodb buffer usage
 - stacked line graph
 - %
innodb reads per second
 - stacked line graph
 - count
innodb writes per second
 - stacked line graph
 - count
