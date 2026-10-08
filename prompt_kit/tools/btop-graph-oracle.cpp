// Independent oracle for sugar-dash DualSampleGraph (candy-top lane L3).
//
// Body of Graph::_create + the Graph ctor copied verbatim from
// aristocratos/btop src/btop_draw.cpp (graph_symbols :89-133, _create :422-492,
// ctor :496-522) with only the terminal plumbing stubbed out so the output is
// a comparable token stream:
//   Mv::r(n)            -> "~" x n   (transparent cell: whatever is under it shows)
//   Theme::g(..).at(k)  -> "{k}"     (gradient index 0..100)
//   Fx::reset           -> "{R}"
//   Mv::d(1)+Mv::l(w)   -> "\n"      (next graph row)
// Config::getB("tty_mode") is false; symbol is passed explicitly.
//
// Build + regenerate the PHPUnit fixture:
//   g++ -std=c++20 -O1 -o /tmp/btop-graph-oracle prompt_kit/tools/btop-graph-oracle.cpp
//   /tmp/btop-graph-oracle > sugar-dash/tests/fixtures/btop-graph-oracle.json
#include <algorithm>
#include <array>
#include <cmath>
#include <cstdint>
#include <cstdio>
#include <deque>
#include <string>
#include <unordered_map>
#include <vector>

using namespace std::string_literals;
using std::array; using std::clamp; using std::deque; using std::max; using std::round; using std::string; using std::vector;

static const std::unordered_map<string, vector<string>> graph_symbols = {
	{ "braille_up", {
		" ", "⢀", "⢠", "⢰", "⢸",
		"⡀", "⣀", "⣠", "⣰", "⣸",
		"⡄", "⣄", "⣤", "⣴", "⣼",
		"⡆", "⣆", "⣦", "⣶", "⣾",
		"⡇", "⣇", "⣧", "⣷", "⣿"
	}},
	{"braille_down", {
		" ", "⠈", "⠘", "⠸", "⢸",
		"⠁", "⠉", "⠙", "⠹", "⢹",
		"⠃", "⠋", "⠛", "⠻", "⢻",
		"⠇", "⠏", "⠟", "⠿", "⢿",
		"⡇", "⡏", "⡟", "⡿", "⣿"
	}},
	{"block_up", {
		" ", "▗", "▗", "▐", "▐",
		"▖", "▄", "▄", "▟", "▟",
		"▖", "▄", "▄", "▟", "▟",
		"▌", "▙", "▙", "█", "█",
		"▌", "▙", "▙", "█", "█"
	}},
	{"block_down", {
		" ", "▝", "▝", "▐", "▐",
		"▘", "▀", "▀", "▜", "▜",
		"▘", "▀", "▀", "▜", "▜",
		"▌", "▛", "▛", "█", "█",
		"▌", "▛", "▛", "█", "█"
	}},
	{"tty_up", {
		" ", "░", "░", "▒", "▒",
		"░", "░", "▒", "▒", "█",
		"░", "▒", "▒", "▒", "█",
		"▒", "▒", "▒", "█", "█",
		"▒", "█", "█", "█", "█"
	}},
	{"tty_down", {
		" ", "░", "░", "▒", "▒",
		"░", "░", "▒", "▒", "█",
		"░", "▒", "▒", "▒", "█",
		"▒", "▒", "▒", "█", "█",
		"▒", "█", "█", "█", "█"
	}}
};

static string operator*(const string& s, size_t n) { string o; for (size_t i = 0; i < n; i++) o += s; return o; }
static string Mv_r(int n) { return string("~") * (size_t)n; }
static string G(const string& grad, long long k) { (void)grad; return "{" + std::to_string(k) + "}"; }

struct Graph {
	int width, height;
	string color_gradient;
	string out, symbol = "default";
	bool invert, no_zero;
	long long offset;
	long long last = 0, max_value = 0;
	bool current = true, tty_mode = false;
	std::unordered_map<bool, vector<string>> graphs = { {true, {}}, {false, {}}};

	void _create(const deque<long long>& data, int data_offset) {
		bool mult = (data.size() - data_offset > 1);
		const auto& graph_symbol = graph_symbols.at(symbol + '_' + (invert ? "down" : "up"));
		array<int, 2> result;
		const float mod = (height == 1) ? 0.3 : 0.1;
		long long data_value = 0;
		if (mult and data_offset > 0) {
			last = data.at(data_offset - 1);
			if (max_value > 0) last = clamp((last + offset) * 100 / max_value, 0ll, 100ll);
		}
		for (int i = data_offset; i < (int)data.size(); i++) {
			if (not tty_mode and mult) current = not current;
			if (i < 0) {
				data_value = 0;
				last = 0;
			}
			else {
				data_value = data.at(i);
				if (max_value > 0) data_value = clamp((data_value + offset) * 100 / max_value, 0ll, 100ll);
			}
			for (int horizon = 0; horizon < height; horizon++) {
				const int cur_high = (height > 1) ? round(100.0 * (height - horizon) / height) : 100;
				const int cur_low = (height > 1) ? round(100.0 * (height - (horizon + 1)) / height) : 0;
				int ai = 0;
				for (const auto& value : {last, data_value}) {
					const int clamp_min = (no_zero and horizon == height - 1 and not (mult and i == data_offset and ai == 0)) ? 1 : 0;
					if (value >= cur_high)
						result[ai++] = 4;
					else if (value <= cur_low)
						result[ai++] = clamp_min;
					else {
						result[ai++] = clamp((int)round((float)(value - cur_low) * 4 / (cur_high - cur_low) + mod), clamp_min, 4);
					}
				}
				if (height == 1) {
					if (result.at(0) + result.at(1) == 0) graphs.at(current).at(horizon) += Mv_r(1);
					else {
						if (not color_gradient.empty()) graphs.at(current).at(horizon) += G(color_gradient, clamp(max(last, data_value), 0ll, 100ll));
						graphs.at(current).at(horizon) += graph_symbol.at((result.at(0) * 5 + result.at(1)));
					}
				}
				else graphs.at(current).at(horizon) += graph_symbol.at((result.at(0) * 5 + result.at(1)));
			}
			if (mult and i >= 0) last = data_value;
		}
		last = data_value;
		out.clear();
		if (height == 1) {
			out += graphs.at(current).at(0);
		}
		else {
			for (int i = 1; i < height + 1; i++) {
				if (i > 1) out += "\n";
				if (not color_gradient.empty())
					out += (invert) ? G(color_gradient, i * 100 / height) : G(color_gradient, 100 - ((i - 1) * 100 / height));
				out += (invert) ? graphs.at(current).at(height - i) : graphs.at(current).at(i-1);
			}
		}
		if (not color_gradient.empty()) out += "{R}";
	}

	Graph(int width, int height, const string& color_gradient,
		  const deque<long long>& data, const string& symbol,
		  bool invert, bool no_zero, long long max_value, long long offset)
	: width(width), height(height), color_gradient(color_gradient),
	  invert(invert), no_zero(no_zero), offset(offset) {
		this->symbol = symbol;
		if (this->symbol == "tty") tty_mode = true;

		if (max_value == 0 and offset > 0) max_value = 100;
		this->max_value = max_value;
		const int value_width = (tty_mode ? data.size() : ceil((double)data.size() / 2));
		int data_offset = (value_width > width) ? data.size() - width * (tty_mode ? 1 : 2) : 0;

		if (not tty_mode and (data.size() - data_offset) % 2 != 0) {
			data_offset--;
		}
		for (int i = 0; i < height * 2; i++) {
			if (tty_mode and i % 2 != current) continue;
			graphs[(i % 2 != 0)].push_back((value_width < width) ? ((height == 1) ? Mv_r(1) : " "s) * (width - value_width) : "");
		}
		if (data.size() == 0) return;
		this->_create(data, data_offset);
	}
};

static uint32_t rng = 20261008u;
static uint32_t next() { rng = rng * 1664525u + 1013904223u; return rng >> 8; }

static string jstr(const string& s) {
	string o = "\"";
	for (char c : s) {
		if (c == '"' || c == '\\') { o += '\\'; o += c; }
		else if (c == '\n') o += "\\n";
		else o += c;
	}
	return o + "\"";
}

int main() {
	// Values chosen to straddle band edges at heights 1..5 plus out-of-range.
	const vector<long long> pool = {-25, -1, 0, 1, 2, 5, 7, 12, 13, 19, 20, 21, 25, 30, 33, 37, 38, 40, 45, 50, 55, 60, 62,
		63, 66, 67, 70, 75, 80, 87, 88, 95, 99, 100, 101, 150, 250};
	const vector<string> families = {"braille", "block", "tty"};
	const vector<int> heights = {1, 2, 5};
	const vector<array<long long, 2>> scales = {{0, 0}, {50, -5}, {0, 10}};
	std::printf("[\n");
	bool first = true;
	for (const auto& fam : families)
	for (int h : heights)
	for (int inv = 0; inv < 2; inv++)
	for (int nz = 0; nz < 2; nz++)
	for (const auto& sc : scales)
	for (int grad = 0; grad < 2; grad++)
	for (int len : {1, 6, 7, 13}) {
		const int w = 3 + (int)(next() % 3); // 3..5
		deque<long long> data;
		for (int k = 0; k < len; k++) data.push_back(pool[next() % pool.size()]);
		Graph g(w, h, grad ? "x" : "", data, fam, inv, nz, sc[0], sc[1]);
		std::printf("%s{\"family\":%s,\"width\":%d,\"height\":%d,\"invert\":%s,\"noZero\":%s,\"maxValue\":%lld,\"offset\":%lld,\"gradient\":%s,\"data\":[",
			first ? "" : ",\n", jstr(fam).c_str(), w, h, inv ? "true" : "false", nz ? "true" : "false", sc[0], sc[1], grad ? "true" : "false");
		for (size_t k = 0; k < data.size(); k++) std::printf("%s%lld", k ? "," : "", data[k]);
		std::printf("],\"out\":%s}", jstr(g.out).c_str());
		first = false;
	}
	std::printf("\n]\n");
	return 0;
}
