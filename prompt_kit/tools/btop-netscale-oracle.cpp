// Independent oracle for sugar-dash Foundation\NetAutoScale (candy-top lane L7).
//
// The counter-update and rescale blocks are transcribed from aristocratos/btop
// src/linux/btop_collect.cpp Net::collect (max_count/graph_max/rescale, the
// `net_auto and selected_iface == iface` block and the `if (net_auto)` rescale
// pass) for a single selected interface with net_auto on. Speeds are random
// with a sticky regime so both fast and slow counters reach 5; 1/40 ticks
// simulate an interface switch (counters reset + rescale armed). The forced
// reset is applied before the counter pass rather than after it as in btop —
// equivalent, because the armed rescale zeroes every counter in that tick.
//
// Build: g++ -O2 -std=c++20 -o oracle btop-netscale-oracle.cpp
// Usage: ./oracle <net_sync 0|1> <seed> <ticks>
// Row:   forced download upload dl_fast dl_slow ul_fast ul_slow dl_max ul_max
// Fixture: sugar-dash/tests/fixtures/btop-netscale-oracle.json (sync 0/1 ×
// seeds 7,42,99 × 200 ticks), consumed by NetAutoScaleOracleTest.
#include <bits/stdc++.h>
using namespace std;
// Verbatim transcription of btop Net::collect autoscale (single iface, net_auto on).
int main(int argc,char**argv){
  bool net_sync = atoi(argv[1]); unsigned seed=atoi(argv[2]); int n=atoi(argv[3]);
  mt19937 rng(seed);
  unordered_map<string,uint64_t> graph_max={{"download",0},{"upload",0}};
  unordered_map<string,array<int,2>> max_count={{"download",{}},{"upload",{}}};
  unordered_map<string,uint64_t> speed={{"download",0},{"upload",0}};
  unordered_map<string,deque<uint64_t>> bw; int mode=0; bool rescale=true; size_t width=40;
  for(int t=0;t<n;t++){
    if(rng()%8==0) mode=rng()%4; uint64_t s[2]; int forced=0;
    for(int k=0;k<2;k++){ uint64_t r=rng(); s[k]= mode==0? r%2000 : mode==1? r%50000 : mode==2? r%3000000 : r%200000000; }
    if(rng()%40==0){ max_count["download"][0]=max_count["download"][1]=max_count["upload"][0]=max_count["upload"][1]=0; rescale=true; forced=1; }
    int k=0;
    for(const string dir:{"download","upload"}){
      speed[dir]=s[k++]; bw[dir].push_back(speed[dir]); while(bw[dir].size()>width*2) bw[dir].pop_front();
      if(net_sync and speed[dir] < speed[dir=="download"?"upload":"download"]) continue;
      if(speed[dir]>graph_max[dir]){ ++max_count[dir][0]; if(max_count[dir][1]>0) --max_count[dir][1]; }
      else if(graph_max[dir] > 10<<10 and speed[dir] < graph_max[dir]/10){ ++max_count[dir][1]; if(max_count[dir][0]>0) --max_count[dir][0]; }
    }
    bool sync=false;
    for(const auto& dir:{"download","upload"}){
      for(const auto& sel:{0,1}){
        if(rescale or max_count[dir][sel]>=5){
          const long long avg_speed=(bw[dir].size()>5? std::accumulate(bw[dir].rbegin(),bw[dir].rbegin()+5,0ll)/5 : speed[dir]);
          graph_max[dir]=max(uint64_t(avg_speed*(sel==0?1.3:3.0)),(uint64_t)10<<10);
          max_count[dir][0]=max_count[dir][1]=0; if(net_sync) sync=true; break;
        }
      }
      if(sync){ const auto other=(string(dir)=="upload"?"download":"upload"); graph_max[other]=graph_max[dir]; max_count[other][0]=max_count[other][1]=0; break; }
    }
    rescale=false;
    printf("%d %llu %llu %d %d %d %d %llu %llu\n",forced,(unsigned long long)s[0],(unsigned long long)s[1],max_count["download"][0],max_count["download"][1],max_count["upload"][0],max_count["upload"][1],(unsigned long long)graph_max["download"],(unsigned long long)graph_max["upload"]);
  }
}
