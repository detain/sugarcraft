<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

/**
 * Real ipmitool output from an ASUS ESC8000A-E12 (AMI BMC, 4 PSUs, 11 fans
 * in RPM, voltages with all six thresholds), captured read-only on
 * 2026-10-08 and sanitized (serials, part numbers, IPs and MACs replaced
 * by RFC 5737 / RFC 7042 documentation values) — the data behind
 * {@see FakeIpmi::demo()}. Verbatim copies live under
 * tests/fixtures/ipmi/skynet2/ with the HP iLO 4 set beside them.
 */
final class FakeIpmiCaptures
{
    public const MC_INFO = 'Device ID                 : 32
Device Revision           : 1
Firmware Revision         : 1.02
IPMI Version              : 2.0
Manufacturer ID           : 2623
Manufacturer Name         : ASUSTek Computer Inc.
Product ID                : 4499 (0x1193)
Product Name              : Unknown (0x1193)
Device Available          : yes
Provides Device SDRs      : yes
Additional Device Support :
    Sensor Device
    SDR Repository Device
    SEL Device
    FRU Inventory Device
    IPMB Event Receiver
    IPMB Event Generator
    Chassis Device
Aux Firmware Rev Info     : 
    0x22
    0x00
    0x00
    0x00
';

    public const FRU = ' Chassis Type          : Rack Mount Chassis
 Chassis Part Number   : PN-000000
 Chassis Serial        : SN0000000000
 Board Mfg Date        : Thu 27 Feb 2025 05:02:00 AM EST EST
 Board Mfg             : ASUSTeK COMPUTER INC.
 Board Product         : K14PG-D24 Series
 Board Serial          : SN0000000000
 Board Part Number     : PN-000000
 Product Manufacturer  : ASUSTeK COMPUTER INC.
 Product Name          : ESC8000A-E12
 Product Version       : SKU1A
 Product Serial        : SN0000000000
';

    public const LAN = 'Set in Progress         : Set Complete
Auth Type Support       : MD5 
Auth Type Enable        : Callback : MD5 
                        : User     : MD5 
                        : Operator : MD5 
                        : Admin    : MD5 
                        : OEM      : MD5 
IP Address Source       : DHCP Address
IP Address              : 192.0.2.12
Subnet Mask             : 255.255.255.192
MAC Address             : 00:00:5e:00:53:a8
SNMP Community String   : AMI
IP Header               : TTL=0x40 Flags=0x40 Precedence=0x00 TOS=0x10
BMC ARP Control         : ARP Responses Enabled, Gratuitous ARP Disabled
Gratituous ARP Intrvl   : 1.0 seconds
Default Gateway IP      : 192.0.2.13
Default Gateway MAC     : 00:00:5e:00:53:8a
Backup Gateway IP       : 0.0.0.0
Backup Gateway MAC      : 00:00:00:00:00:00
802.1q VLAN ID          : Disabled
802.1q VLAN Priority    : 0
RMCP+ Cipher Suites     : 0,1,2,3,6,7,8,11,12,15,16,17
Cipher Suite Priv Max   : XaaaaaaaaaaaXXX
                        :     X=Cipher Suite Unused
                        :     c=CALLBACK
                        :     u=USER
                        :     o=OPERATOR
                        :     a=ADMIN
                        :     O=OEM
Bad Password Threshold  : 0
Invalid password disable: no
Attempt Count Reset Int.: 0
User Lockout Interval   : 0
';

    public const DCMI = '
    Instantaneous power reading:                  2064 Watts
    Minimum during sampling period:                256 Watts
    Maximum during sampling period:               2240 Watts
    Average power reading over sample period:      320 Watts
    IPMI timestamp:                           10/08/2026 06:02:12 PM EDT    Sampling period:                          00000005 Seconds.
    Power reading state is:                   activated


';

    public const CHASSIS = 'System Power         : on
Power Overload       : false
Power Interlock      : inactive
Main Power Fault     : false
Power Control Fault  : false
Power Restore Policy : always-on
Last Power Event     : command
Chassis Intrusion    : inactive
Front-Panel Lockout  : inactive
Drive Fault          : false
Cooling/Fan Fault    : false
Sleep Button Disable : allowed
Diag Button Disable  : allowed
Reset Button Disable : allowed
Power Button Disable : allowed
Sleep Button Disabled: false
Diag Button Disabled : false
Reset Button Disabled: false
Power Button Disabled: false
';

    public const SEL_INFO = 'SEL Information
Version          : 1.5 (v1.5, v2 compliant)
Entries          : 107
Free Space       : 63576 bytes 
Percent Used     : 2%
Last Add Time    : 09/20/2026 01:40:17 PM EDT
Last Del Time    : Not Available
Overflow         : false
Supported Cmds   : \'Delete\' \'Partial Add\' \'Reserve\' \'Get Alloc Info\' 
# of Alloc Units : 3639
Alloc Unit Size  : 18
# Free Units     : 3532
Largest Free Blk : 3532
Max Record Size  : 1
';

    public const SEL_LAST = '  67 | 09/20/2026 | 12:53:45 PM EDT | Power Unit PowerUnit | Power off/down | Deasserted
  68 | 09/20/2026 | 01:24:54 PM EDT | Power Unit PowerUnit | Power off/down | Asserted
  69 | 09/20/2026 | 01:32:36 PM EDT | Power Unit PowerUnit | Power off/down | Deasserted
  6a | 09/20/2026 | 01:40:05 PM EDT | Power Unit PowerUnit | Power off/down | Asserted
  6b | 09/20/2026 | 01:40:17 PM EDT | Power Unit PowerUnit | Power off/down | Deasserted
';

    public const SENSORS = 'CPU1 Temperature | 45.000     | degrees C  | ok    | na        | na        | na        | 92.000    | 93.000    | 95.000    
CPU2 Temperature | na         |            | na    | na        | na        | na        | 92.000    | 93.000    | 95.000    
TR2 Temperature  | 73.000     | degrees C  | ok    | na        | na        | na        | 90.000    | 92.000    | 94.000    
TR3 Temperature  | 81.000     | degrees C  | ok    | na        | na        | na        | 90.000    | 92.000    | 94.000    
TR4 Temperature  | 79.000     | degrees C  | ok    | na        | na        | na        | 90.000    | 92.000    | 94.000    
TR5 Temperature  | 66.000     | degrees C  | ok    | na        | na        | na        | 90.000    | 92.000    | 94.000    
TR6 Temperature  | na         | degrees C  | na    | na        | na        | na        | 90.000    | 95.000    | 100.000   
TR7 Temperature  | na         | degrees C  | na    | na        | na        | na        | 90.000    | 95.000    | 100.000   
TR8 Temperature  | na         | degrees C  | na    | na        | na        | na        | 90.000    | 95.000    | 100.000   
TR9 Temperature  | na         | degrees C  | na    | na        | na        | na        | 90.000    | 95.000    | 100.000   
CPU1_DIMMA1_Temp | 27.000     | degrees C  | ok    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU1_DIMMB1_Temp | 28.000     | degrees C  | ok    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU1_DIMMC1_Temp | 28.000     | degrees C  | ok    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU1_DIMMD1_Temp | na         | degrees C  | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU1_DIMME1_Temp | 27.000     | degrees C  | ok    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU1_DIMMF1_Temp | na         | degrees C  | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU1_DIMMG1_Temp | 25.000     | degrees C  | ok    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU1_DIMMH1_Temp | 27.000     | degrees C  | ok    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU1_DIMMI1_Temp | 26.000     | degrees C  | ok    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU1_DIMMJ1_Temp | na         | degrees C  | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU1_DIMMK1_Temp | 25.000     | degrees C  | ok    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU1_DIMML1_Temp | na         | degrees C  | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU2_DIMMA1_Temp | na         |            | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU2_DIMMB1_Temp | na         |            | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU2_DIMMC1_Temp | na         |            | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU2_DIMMD1_Temp | na         |            | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU2_DIMME1_Temp | na         |            | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU2_DIMMF1_Temp | na         |            | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU2_DIMMG1_Temp | na         |            | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU2_DIMMH1_Temp | na         |            | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU2_DIMMI1_Temp | na         |            | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU2_DIMMJ1_Temp | na         |            | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU2_DIMMK1_Temp | na         |            | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
CPU2_DIMML1_Temp | na         |            | na    | na        | na        | na        | 81.000    | 83.000    | 85.000    
Inlet Temp       | 19.000     | degrees C  | ok    | na        | na        | na        | 37.000    | 40.000    | 60.000    
+VCORE0_CPU1     | 1.143      | Volts      | ok    | 0.468     | 0.495     | 0.522     | 2.097     | 2.196     | 2.295     
+VCORE1_CPU1     | 1.143      | Volts      | ok    | 0.468     | 0.495     | 0.522     | 2.097     | 2.196     | 2.295     
+VCORE0_CPU2     | na         |            | na    | 0.468     | 0.495     | 0.522     | 2.097     | 2.196     | 2.295     
+VCORE1_CPU2     | na         |            | na    | 0.468     | 0.495     | 0.522     | 2.097     | 2.196     | 2.295     
+VSOC_CPU1       | 1.002      | Volts      | ok    | 0.636     | 0.672     | 0.714     | 1.260     | 1.320     | 1.380     
+VSOC_CPU2       | na         |            | na    | 0.636     | 0.672     | 0.714     | 1.260     | 1.320     | 1.380     
+VDDIO_CPU1      | 1.110      | Volts      | ok    | 0.762     | 0.810     | 0.852     | 1.260     | 1.320     | 1.380     
+VDDIO_CPU2      | na         |            | na    | 0.762     | 0.810     | 0.852     | 1.260     | 1.320     | 1.380     
+12V             | 12.354     | Volts      | ok    | 9.628     | 10.208    | 10.788    | 13.224    | 13.804    | 14.384    
+5V              | 5.088      | Volts      | ok    | 4.008     | 4.248     | 4.512     | 5.496     | 5.760     | 6.000     
+3.3V            | 3.360      | Volts      | ok    | 2.640     | 2.800     | 2.976     | 3.632     | 3.792     | 3.968     
+5VSB            | 5.088      | Volts      | ok    | 4.008     | 4.248     | 4.512     | 5.496     | 5.760     | 6.000     
+3.3VSB          | 3.360      | Volts      | ok    | 2.640     | 2.800     | 2.976     | 3.632     | 3.792     | 3.968     
VBAT             | 3.120      | Volts      | ok    | 2.400     | 2.560     | 2.700     | 3.640     | 3.800     | 3.960     
GPU_FAN1         | 16510.000  | RPM        | ok    | 0.000     | 520.000   | 520.000   | na        | na        | na        
GPU_FAN2         | 16510.000  | RPM        | ok    | 0.000     | 520.000   | 520.000   | na        | na        | na        
GPU_FAN3         | 15990.000  | RPM        | ok    | 0.000     | 520.000   | 520.000   | na        | na        | na        
GPU_FAN4         | 15860.000  | RPM        | ok    | 0.000     | 520.000   | 520.000   | na        | na        | na        
GPU_FAN5         | 15990.000  | RPM        | ok    | 0.000     | 520.000   | 520.000   | na        | na        | na        
SYS_FAN1         | 17940.000  | RPM        | ok    | 0.000     | 520.000   | 520.000   | na        | na        | na        
SYS_FAN2         | 17810.000  | RPM        | ok    | 0.000     | 520.000   | 520.000   | na        | na        | na        
SYS_FAN3         | 17680.000  | RPM        | ok    | 0.000     | 520.000   | 520.000   | na        | na        | na        
SYS_FAN4         | 17810.000  | RPM        | ok    | 0.000     | 520.000   | 520.000   | na        | na        | na        
SYS_FAN5         | 17810.000  | RPM        | ok    | 0.000     | 520.000   | 520.000   | na        | na        | na        
SYS_FAN6         | 17940.000  | RPM        | ok    | 0.000     | 520.000   | 520.000   | na        | na        | na        
PSU1 Power In    | 480.000    | Watts      | ok    | na        | na        | na        | na        | na        | na        
PSU1 Power Out   | 448.000    | Watts      | ok    | na        | na        | na        | 2464.000  | 2592.000  | 2720.000  
PSU2 Power In    | 496.000    | Watts      | ok    | na        | na        | na        | na        | na        | na        
PSU2 Power Out   | 464.000    | Watts      | ok    | na        | na        | na        | 2464.000  | 2592.000  | 2720.000  
PSU3 Power In    | 480.000    | Watts      | ok    | na        | na        | na        | na        | na        | na        
PSU3 Power Out   | 480.000    | Watts      | ok    | na        | na        | na        | 2464.000  | 2592.000  | 2720.000  
PSU4 Power In    | 512.000    | Watts      | ok    | na        | na        | na        | na        | na        | na        
PSU4 Power Out   | 496.000    | Watts      | ok    | na        | na        | na        | 2464.000  | 2592.000  | 2720.000  
CPU_Power        | 112.000    | Watts      | ok    | na        | na        | na        | na        | na        | na        
Memory_Power     | 8.000      | Watts      | ok    | na        | na        | na        | na        | na        | na        
Backplane1 HD01  | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
Backplane1 HD02  | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
Backplane1 HD03  | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
Backplane1 HD04  | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
Backplane1 HD05  | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
Backplane1 HD06  | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
Backplane1 HD07  | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
Backplane1 HD08  | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
PSU1 Over Temp   | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU1 AC Lost     | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU1 Slow FAN1   | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU1 PWR Detect  | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU1 Over Curr   | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU2 Over Temp   | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU2 AC Lost     | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU2 Slow FAN1   | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU2 PWR Detect  | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU2 Over Curr   | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU3 Over Temp   | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU3 AC Lost     | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU3 Slow FAN1   | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU3 PWR Detect  | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU3 Over Curr   | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU4 Over Temp   | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU4 AC Lost     | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU4 Slow FAN1   | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU4 PWR Detect  | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
PSU4 Over Curr   | 0x1        | discrete   | 0x0180| na        | na        | na        | na        | na        | na        
CPU1_ECC1        | 0x40       | discrete   | 0x4080| na        | na        | na        | na        | na        | na        
CPU2_ECC1        | na         | discrete   | na    | na        | na        | na        | na        | na        | na        
Memory_Train_ERR | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
BIOS Event       | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
PowerUnit        | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
CPU_Hardware     | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
Mem_Hardware     | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
PCIE_Hardware    | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
Watchdog2        | 0x0        | discrete   | 0x0080| na        | na        | na        | na        | na        | na        
';

    private function __construct()
    {
    }

    /**
     * The captures keyed like {@see FakeIpmi::fromCaptures()} expects.
     *
     * @return array<string, string>
     */
    public static function skynet2(): array
    {
        return [
            'mc' => self::MC_INFO,
            'fru' => self::FRU,
            'lan' => self::LAN,
            'dcmi' => self::DCMI,
            'chassis' => self::CHASSIS,
            'sel' => self::SEL_INFO,
            'sel_last' => self::SEL_LAST,
            'sensors' => self::SENSORS,
        ];
    }
}
