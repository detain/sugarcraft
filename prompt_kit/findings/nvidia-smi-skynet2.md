# nvidia-smi reference output — skynet2 (captured 2026-10-08)

4× NVIDIA RTX PRO 6000 Blackwell Max-Q, driver/NVIDIA-SMI 595.91.07, NVML 595.91, CUDA 13.2, persistence on (query ~70 ms). Used as real-hardware fixtures for candy-top's Gpu collector (Wave U0/U1, post-v1 P-I #1552 per-process GPU).

## `nvidia-smi -L`

```
GPU 0: NVIDIA RTX PRO 6000 Blackwell Max-Q Workstation Edition (UUID: GPU-e16d248c-c1a9-3302-01d6-82c18e02c9ce)
GPU 1: NVIDIA RTX PRO 6000 Blackwell Max-Q Workstation Edition (UUID: GPU-45007176-8a28-3db0-cea5-27903b16107b)
GPU 2: NVIDIA RTX PRO 6000 Blackwell Max-Q Workstation Edition (UUID: GPU-d9a18a51-6b47-71b3-44aa-dfd4448a0e88)
GPU 3: NVIDIA RTX PRO 6000 Blackwell Max-Q Workstation Edition (UUID: GPU-e3bec724-5bbb-759f-fdfd-e690b400593f)
```

## `nvidia-smi --version`

```
NVIDIA-SMI version  : 595.91.07
NVML version        : 595.91
DRIVER version      : 595.91.07
CUDA Version        : 13.2
```

## `nvidia-smi --query-gpu=index,uuid,name,pci.bus_id,driver_version,utilization.gpu,utilization.memory,memory.total,memory.used,memory.free,temperature.gpu,temperature.memory,power.draw,power.limit,clocks.gr,clocks.mem,clocks.max.gr,clocks.max.mem,fan.speed,pstate,pcie.link.gen.current,pcie.link.width.current,encoder.stats.sessionCount --format=csv,noheader,nounits`

```
0, GPU-e16d248c-c1a9-3302-01d6-82c18e02c9ce, NVIDIA RTX PRO 6000 Blackwell Max-Q Workstation Edition, 00000000:01:00.0, 595.91.07, 99, 55, 97887, 90538, 6712, 81, N/A, 299.35, 300.00, 1927, 13365, 3090, 14001, 54, P1, 5, 16, 0
1, GPU-45007176-8a28-3db0-cea5-27903b16107b, NVIDIA RTX PRO 6000 Blackwell Max-Q Workstation Edition, 00000000:41:00.0, 595.91.07, 99, 52, 97887, 90536, 6714, 66, N/A, 287.14, 300.00, 1995, 13365, 3090, 14001, 40, P1, 5, 16, 0
2, GPU-d9a18a51-6b47-71b3-44aa-dfd4448a0e88, NVIDIA RTX PRO 6000 Blackwell Max-Q Workstation Edition, 00000000:81:00.0, 595.91.07, 100, 64, 97887, 48243, 49007, 79, N/A, 299.95, 300.00, 1147, 13365, 3090, 14001, 52, P1, 5, 16, 0
3, GPU-e3bec724-5bbb-759f-fdfd-e690b400593f, NVIDIA RTX PRO 6000 Blackwell Max-Q Workstation Edition, 00000000:C1:00.0, 595.91.07, 100, 65, 97887, 48209, 49041, 71, N/A, 300.00, 300.00, 1177, 13365, 3090, 14001, 43, P1, 5, 16, 0
```

## `nvidia-smi --query-gpu=index,power.draw.average,power.draw.instant,clocks_throttle_reasons.active,memory.reserved,ecc.mode.current,mig.mode.current --format=csv,noheader,nounits`

```
0, 297.37, 307.72, 0x0000000000000004, 638, Disabled, [N/A]
1, 289.76, 309.90, 0x0000000000000004, 638, Disabled, [N/A]
2, 300.00, 299.80, 0x0000000000000004, 638, Disabled, [N/A]
3, 299.99, 299.46, 0x0000000000000004, 638, Disabled, [N/A]
```

## `nvidia-smi --query-compute-apps=pid,process_name,gpu_uuid,used_memory --format=csv,noheader,nounits`

```
2094147, sglang::scheduler_TP0_EP0, GPU-e16d248c-c1a9-3302-01d6-82c18e02c9ce, 90480
2094383, sglang::scheduler_TP1_EP1, GPU-45007176-8a28-3db0-cea5-27903b16107b, 90478
377306, /root/sglang/.venv/bin/python3, GPU-d9a18a51-6b47-71b3-44aa-dfd4448a0e88, 550
377813, sgl_diffusion::scheduler, GPU-d9a18a51-6b47-71b3-44aa-dfd4448a0e88, 47594
377306, /root/sglang/.venv/bin/python3, GPU-e3bec724-5bbb-759f-fdfd-e690b400593f, 550
377814, sgl_diffusion::scheduler, GPU-e3bec724-5bbb-759f-fdfd-e690b400593f, 47560
```

## `nvidia-smi pmon -c 1 -s um`

```
# gpu         pid   type     sm    mem    enc    dec    jpg    ofa     fb   ccpm    command 
# Idx           #    C/G      %      %      %      %      %      %     MB     MB    name 
    0    2094147     C     92     52      -      -      -      -  90480      0    sglang::schedul
    1    2094383     C     91     50      -      -      -      -  90478      0    sglang::schedul
    2     377306     C      -      -      -      -      -      -    550      0    python3        
    2     377813     C     99     60      -      -      -      -  47594      0    sgl_diffusion::
    3     377306     C      -      -      -      -      -      -    550      0    python3        
    3     377814     C    100     70      -      -      -      -  47560      0    sgl_diffusion::
```

## `nvidia-smi dmon -c 1 -s pucm`

```
# gpu    pwr  gtemp  mtemp     sm    mem    enc    dec    jpg    ofa   mclk   pclk     fb   bar1   ccpm 
# Idx      W      C      C      %      %      %      %      %      %    MHz    MHz     MB     MB     MB 
    0    294     81      -     98     57      0      0      0      0  13365   1897  90538      4      0 
    1    292     68      -     99     55      0      0      0      0  13365   2017  90536      4      0 
    2    299     80      -    100     51      0      0      0      0  13365   1192  48243      6      0 
    3    299     71      -    100     58      0      0      0      0  13365   1200  48209      6      0 
```

## `nvidia-smi --query-gpu=bogus.field --format=csv; echo "exit=$?"`

```
Field "bogus.field" is not a valid field to query.

exit=2
```

