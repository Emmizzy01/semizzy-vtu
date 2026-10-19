<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $homepage['browser_title'] ?? 'Semizzy VTU - Single File Dashboard' }}</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
body{font-family:'Inter',sans-serif}
.hide-scrollbar::-webkit-scrollbar{display:none}
.hide-scrollbar{-ms-overflow-style:none;scrollbar-width:none}
</style>
</head>
<body class="bg-[#eef4ef] min-h-screen">
<!-- Toggle -->
<div class="fixed top-3 left-1/2 -translate-x-1/2 z-[100] bg-white/90 backdrop-blur-xl rounded-full p-1.5 shadow-[0_8px_24px_rgba(0,0,0,0.12)] border border-black/5 flex gap-1">
<button id="btn-user" onclick="switchView('user')" class="px-5 py-2 rounded-full text-[13px] font-semibold bg-[#0f8a4f] text-white shadow">User Dashboard</button>
<button id="btn-admin" onclick="switchView('admin')" class="px-5 py-2 rounded-full text-[13px] font-semibold text-[#6b7280]">Admin Dashboard</button>
</div>

<!-- USER DASHBOARD -->
<div id="view-user" class="max-w-[390px] md:max-w-[1200px] mx-auto md:flex pt-16">
<!-- Desktop Sidebar -->
<aside class="hidden md:block w-[260px] bg-white border-r min-h-screen p-6 sticky top-0">
<div class="flex items-center gap-2 font-bold text-xl"><span class="w-8 h-8 bg-[#0f8a4f] rounded-full grid place-items-center text-white text-sm">S</span>Semizzy</div>
<div class="mt-6 bg-gradient-to-br from-[#0e7a44] to-[#0a5c32] rounded-xl p-4 text-white">
<p class="text-xs opacity-80">Wallet Balance</p><p class="text-xl font-bold mt-1">₦125,450.00</p>
<div class="flex gap-2 mt-3"><button class="flex-1 bg-white text-[#0a5c32] rounded-lg py-2 text-xs font-bold">Fund Wallet</button><button class="flex-1 bg-[#12a15a] text-white rounded-lg py-2 text-xs font-bold">Transfer</button></div>
</div>
<nav class="mt-6 space-y-1 text-[13px] text-gray-600">
<div class="p-2.5 rounded-lg bg-[#0f8a4f]/10 text-[#0f8a4f] font-bold">🏠 Dashboard</div>
<div class="p-2.5 rounded-lg">📱 Buy Airtime</div>
<div class="p-2.5 rounded-lg">📶 Buy Data</div>
<div class="p-2.5 rounded-lg">💡 Electricity</div>
<div class="p-2.5 rounded-lg">📺 Cable TV</div>
<div class="p-2.5 rounded-lg">🌐 Internet</div>
<div class="p-2.5 rounded-lg">📜 Transactions</div>
</nav>
</aside>

<main class="flex-1 p-4 pb-24 md:p-8">
<!-- Wallet -->
<div class="bg-gradient-to-br from-[#0e7a44] to-[#0a5c32] rounded-[20px] p-5 text-white shadow relative overflow-hidden">
<p class="text-[13px] opacity-80">Wallet Balance</p>
<div class="flex items-center gap-2 mt-1"><h1 class="text-[32px] font-bold">₦125,450.00</h1><button onclick="toggleBal()" class="opacity-60">👁️</button></div>
<div class="flex gap-3 mt-5">
<button class="flex-1 bg-white text-[#0a5c32] rounded-xl py-3 font-bold text-sm">Fund Wallet</button>
<button class="flex-1 bg-[#12a15a] text-white rounded-xl py-3 font-bold text-sm border border-white/20">Transfer</button>
</div>
<div class="absolute -right-10 -top-10 w-40 h-40 bg-white/10 rounded-full"></div>
</div>

<!-- 1. Airtime Data Bills TV - BEFORE quick top up -->
<div class="grid grid-cols-4 gap-3 mt-6">
<div class="text-center"><div class="bg-white rounded-[16px] h-[64px] grid place-items-center shadow-sm border text-[#0f8a4f] text-xl">📱</div><p class="text-[12px] mt-2 text-gray-600 font-medium">Airtime</p></div>
<div class="text-center"><div class="bg-white rounded-[16px] h-[64px] grid place-items-center shadow-sm border text-[#0f8a4f] text-xl">📶</div><p class="text-[12px] mt-2 text-gray-600 font-medium">Data</p></div>
<div class="text-center"><div class="bg-white rounded-[16px] h-[64px] grid place-items-center shadow-sm border text-[#0f8a4f] text-xl">🧾</div><p class="text-[12px] mt-2 text-gray-600 font-medium">Bills</p></div>
<div class="text-center"><div class="bg-white rounded-[16px] h-[64px] grid place-items-center shadow-sm border text-[#0f8a4f] text-xl">📺</div><p class="text-[12px] mt-2 text-gray-600 font-medium">TV</p></div>
</div>

<!-- 2. Banner Enjoy 10% cashback - ABOVE Buy Data -->
<div class="mt-6 bg-gradient-to-r from-[#0a5c32] to-[#12a15a] rounded-[16px] p-4 flex justify-between items-center text-white h-[96px] relative overflow-hidden">
<div><p class="font-bold text-[14px] leading-5">{{ $homepage['cashback_line_one'] ?? 'Enjoy 10% cashback' }}<br>{{ $homepage['cashback_line_two'] ?? 'on data purchases' }}</p><p class="text-[11px] opacity-80 mt-1">{{ $homepage['cashback_subtitle'] ?? 'Limited time offer' }}</p></div>
<div class="w-20 h-20 bg-white/20 rounded-full grid place-items-center text-3xl">👩‍💼</div>
</div>

<!-- 3. Buy Data / Quick Top Up - BELOW Banner -->
<h3 class="font-bold mt-6 mb-3 text-[15px]">Buy Data</h3>
<div class="grid grid-cols-4 gap-3">
<div class="bg-white rounded-xl p-3 text-center shadow-sm border hover:shadow-md cursor-pointer"><div class="w-8 h-8 mx-auto rounded-full bg-[#FFCC00] grid place-items-center font-bold text-xs">M</div><p class="text-[11px] mt-2 font-medium">MTN</p></div>
<div class="bg-white rounded-xl p-3 text-center shadow-sm border hover:shadow-md cursor-pointer"><div class="w-8 h-8 mx-auto rounded-full bg-[#E30613] text-white grid place-items-center font-bold text-xs">A</div><p class="text-[11px] mt-2 font-medium">Airtel</p></div>
<div class="bg-white rounded-xl p-3 text-center shadow-sm border hover:shadow-md cursor-pointer"><div class="w-8 h-8 mx-auto rounded-full bg-[#0FAA4E] text-white grid place-items-center font-bold text-xs">G</div><p class="text-[11px] mt-2 font-medium">Glo</p></div>
<div class="bg-white rounded-xl p-3 text-center shadow-sm border hover:shadow-md cursor-pointer"><div class="w-8 h-8 mx-auto rounded-full bg-[#D6F5E2] text-[#0f8a4f] grid place-items-center font-bold text-xs">9</div><p class="text-[11px] mt-2 font-medium">9mobile</p></div>
</div>

<!-- 4. Popular Services 4x3 More last -->
<h3 class="font-bold mt-6 mb-3 text-[15px]">Popular Services</h3>
<div class="grid grid-cols-4 gap-3">
<div class="bg-white rounded-xl p-3 text-center shadow-sm border"><div class="w-10 h-10 mx-auto bg-[#0f8a4f]/10 rounded-full grid place-items-center">💡</div><p class="text-[10px] mt-2 text-gray-600 leading-tight font-medium">Electricity</p></div>
<div class="bg-white rounded-xl p-3 text-center shadow-sm border"><div class="w-10 h-10 mx-auto bg-[#0f8a4f]/10 rounded-full grid place-items-center">📺</div><p class="text-[10px] mt-2 text-gray-600 leading-tight font-medium">Cable TV</p></div>
<div class="bg-white rounded-xl p-3 text-center shadow-sm border"><div class="w-10 h-10 mx-auto bg-[#0f8a4f]/10 rounded-full grid place-items-center">🌐</div><p class="text-[10px] mt-2 text-gray-600 leading-tight font-medium">Internet</p></div>
<div class="bg-white rounded-xl p-3 text-center shadow-sm border"><div class="w-10 h-10 mx-auto bg-[#0f8a4f]/10 rounded-full grid place-items-center">💧</div><p class="text-[10px] mt-2 text-gray-600 leading-tight font-medium">Water Bill</p></div>
<div class="bg-white rounded-xl p-3 text-center shadow-sm border"><div class="w-10 h-10 mx-auto bg-[#0f8a4f]/10 rounded-full grid place-items-center">🎲</div><p class="text-[10px] mt-2 text-gray-600 leading-tight font-medium">Betting</p></div>
<div class="bg-white rounded-xl p-3 text-center shadow-sm border"><div class="w-10 h-10 mx-auto bg-[#0f8a4f]/10 rounded-full grid place-items-center">🎓</div><p class="text-[10px] mt-2 text-gray-600 leading-tight font-medium">Education</p></div>
<div class="bg-white rounded-xl p-3 text-center shadow-sm border"><div class="w-10 h-10 mx-auto bg-[#0f8a4f]/10 rounded-full grid place-items-center">💱</div><p class="text-[10px] mt-2 text-gray-600 leading-tight font-medium">Airtime to Cash</p></div>
<div class="bg-white rounded-xl p-3 text-center shadow-sm border"><div class="w-10 h-10 mx-auto bg-[#0f8a4f]/10 rounded-full grid place-items-center">🔑</div><p class="text-[10px] mt-2 text-gray-600 leading-tight font-medium">E-PIN</p></div>
<div class="bg-white rounded-xl p-3 text-center shadow-sm border"><div class="w-10 h-10 mx-auto bg-[#0f8a4f]/10 rounded-full grid place-items-center">💬</div><p class="text-[10px] mt-2 text-gray-600 leading-tight font-medium">Bulk SMS</p></div>
<div class="bg-white rounded-xl p-3 text-center shadow-sm border"><div class="w-10 h-10 mx-auto bg-[#0f8a4f]/10 rounded-full grid place-items-center">🎁</div><p class="text-[10px] mt-2 text-gray-600 leading-tight font-medium">Gift Cards</p></div>
<div class="bg-white rounded-xl p-3 text-center shadow-sm border"><div class="w-10 h-10 mx-auto bg-[#0f8a4f]/10 rounded-full grid place-items-center">✈️</div><p class="text-[10px] mt-2 text-gray-600 leading-tight font-medium">Flight</p></div>
<div class="bg-white rounded-xl p-3 text-center shadow-sm border bg-gray-50"><div class="w-10 h-10 mx-auto bg-black rounded-full grid place-items-center text-white">•••</div><p class="text-[10px] mt-2 text-gray-600 leading-tight font-bold">More</p></div>
</div>

<!-- ADS BANNER 1 BEFORE Shop Gadget -->
<div class="mt-6 bg-gradient-to-r from-[#0f8a4f] to-[#0a5c32] rounded-[16px] p-4 flex justify-between items-center text-white">
<div><p class="font-bold text-[13px]">{{ $homepage['gadget_sale_title'] ?? '🔥 Big Gadget Sale! Up to 50% OFF' }}</p><p class="text-[11px] opacity-80">{{ $homepage['gadget_sale_subtitle'] ?? 'iPhones, Samsung, Laptops' }}</p></div>
<button class="bg-white text-[#0a5c32] px-4 py-2 rounded-full text-xs font-bold">Shop Now</button>
</div>

<!-- SHOP GADGET -->
<div class="mt-6">
<div class="flex justify-between items-center mb-3"><h3 class="font-bold text-[15px]">Shop Gadgets</h3><span class="text-[#0f8a4f] text-xs font-bold">See All →</span></div>
<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
<div class="bg-white rounded-xl p-3 shadow-sm border"><div class="h-[80px] bg-gray-100 rounded-lg grid place-items-center">📱</div><p class="text-[11px] font-bold mt-2">iPhone 14</p><p class="text-[12px] font-bold text-[#0f8a4f]">₦850,000</p></div>
<div class="bg-white rounded-xl p-3 shadow-sm border"><div class="h-[80px] bg-gray-100 rounded-lg grid place-items-center">📱</div><p class="text-[11px] font-bold mt-2">Samsung S23</p><p class="text-[12px] font-bold text-[#0f8a4f]">₦720,000</p></div>
<div class="bg-white rounded-xl p-3 shadow-sm border"><div class="h-[80px] bg-gray-100 rounded-lg grid place-items-center">🔋</div><p class="text-[11px] font-bold mt-2">Oraimo 20k</p><p class="text-[12px] font-bold text-[#0f8a4f]">₦15,000</p></div>
<div class="bg-white rounded-xl p-3 shadow-sm border"><div class="h-[80px] bg-gray-100 rounded-lg grid place-items-center">🎧</div><p class="text-[11px] font-bold mt-2">JBL Headset</p><p class="text-[12px] font-bold text-[#0f8a4f]">₦28,000</p></div>
<div class="bg-white rounded-xl p-3 shadow-sm border"><div class="h-[80px] bg-gray-100 rounded-lg grid place-items-center">💻</div><p class="text-[11px] font-bold mt-2">MacBook Air M2</p><p class="text-[12px] font-bold text-[#0f8a4f]">₦1,200,000</p></div>
<div class="bg-white rounded-xl p-3 shadow-sm border"><div class="h-[80px] bg-gray-100 rounded-lg grid place-items-center">💻</div><p class="text-[11px] font-bold mt-2">HP Laptop</p><p class="text-[12px] font-bold text-[#0f8a4f]">₦450,000</p></div>
<div class="bg-white rounded-xl p-3 shadow-sm border"><div class="h-[80px] bg-gray-100 rounded-lg grid place-items-center">⌚</div><p class="text-[11px] font-bold mt-2">Smart Watch</p><p class="text-[12px] font-bold text-[#0f8a4f]">₦35,000</p></div>
<div class="bg-white rounded-xl p-3 shadow-sm border"><div class="h-[80px] bg-gray-100 rounded-lg grid place-items-center">🎧</div><p class="text-[11px] font-bold mt-2">AirPods Pro</p><p class="text-[12px] font-bold text-[#0f8a4f]">₦95,000</p></div>
</div>
</div>

<!-- ADS BANNER 2 AFTER Shop Gadget -->
<div class="mt-6 bg-[#fef3c7] border border-[#fde68a] rounded-[16px] p-4 flex justify-between items-center">
<div><p class="font-bold text-[13px] text-[#92400e]">{{ $homepage['referral_title'] ?? '💰 Refer & Earn ₦500 per friend!' }}</p><p class="text-[11px] text-[#a16207]">{{ $homepage['referral_subtitle'] ?? 'Invite friends to Semizzy' }}</p></div>
<button class="bg-[#111827] text-white px-4 py-2 rounded-full text-xs font-bold">Refer Now</button>
</div>
</main>
<!-- Mobile Footer -->
<nav class="md:hidden fixed bottom-0 left-0 right-0 h-[72px] bg-white border-t flex items-center justify-around px-2 z-50">
<button class="flex flex-col items-center gap-1"><span class="text-[18px]">🏠</span><span class="text-[10px] text-[#0f8a4f] font-bold">Home</span></button>
<button class="flex flex-col items-center gap-1"><span class="text-[18px]">📜</span><span class="text-[10px] text-gray-400">Orders</span></button>
<button class="flex flex-col items-center gap-1"><span class="text-[18px]">👛</span><span class="text-[10px] text-gray-400">Wallet</span></button>
<button class="flex flex-col items-center gap-1"><span class="text-[18px]">🎧</span><span class="text-[10px] text-gray-400">Support</span></button>
<button class="flex flex-col items-center gap-1"><span class="text-[18px]">👤</span><span class="text-[10px] text-gray-400">Profile</span></button>
</nav>
</div>

<!-- ADMIN DASHBOARD -->
<div id="view-admin" class="hidden max-w-[1400px] mx-auto pt-16 p-4 md:p-6">
<div class="flex justify-between items-center mb-6"><h1 class="text-2xl font-bold">Admin Dashboard</h1><button onclick="toggleDark()" class="px-3 py-1.5 bg-white border rounded-full text-xs">🌙 Dark Mode</button></div>

<!-- Stats 6 -->
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
<div class="bg-white p-4 rounded-xl border shadow-sm"><p class="text-[11px] text-gray-500">Total Revenue</p><p class="font-bold text-lg">₦4,450,200</p><p class="text-[11px] text-green-600">+12% ▲</p></div>
<div class="bg-white p-4 rounded-xl border shadow-sm"><p class="text-[11px] text-gray-500">Active Users</p><p class="font-bold text-lg">12,332</p></div>
<div class="bg-white p-4 rounded-xl border shadow-sm"><p class="text-[11px] text-gray-500">New Users Today</p><p class="font-bold text-lg">87</p><p class="text-[11px] text-green-600">+8 new</p></div>
<div class="bg-white p-4 rounded-xl border shadow-sm"><p class="text-[11px] text-gray-500">High-Value Users</p><p class="font-bold text-lg">156</p></div>
<div class="bg-white p-4 rounded-xl border shadow-sm"><p class="text-[11px] text-gray-500">API Success Rate</p><p class="font-bold text-lg">98.2%</p><div class="h-1.5 bg-gray-100 rounded-full mt-2"><div class="h-full bg-[#0f8a4f] rounded-full" style="width:98%"></div></div></div>
<div class="bg-white p-4 rounded-xl border shadow-sm"><p class="text-[11px] text-gray-500">Pending Txns</p><p class="font-bold text-lg">234</p></div>
</div>

<!-- API Provider Balance Enabled Only -->
<div class="mt-6 bg-white rounded-xl border shadow-sm p-5">
<div class="flex justify-between items-center mb-4"><h3 class="font-bold">API Provider Balance - Enabled Only</h3><span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">● Live</span></div>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
<div class="border rounded-xl p-4"><div class="flex justify-between"><span class="w-8 h-8 bg-yellow-400 rounded-full grid place-items-center font-bold text-xs">V</span><span class="text-[10px] bg-green-100 text-green-700 px-2 py-1 rounded-full">Enabled</span></div><p class="font-bold mt-3">VTU.ng</p><p class="text-lg font-bold text-[#0f8a4f]">₦450,200</p><div class="mt-2"><p class="text-[11px] flex justify-between"><span>Success</span><span>98.5%</span></p><div class="h-1.5 bg-gray-100 rounded-full mt-1"><div class="h-full bg-[#0f8a4f] rounded-full" style="width:98.5%"></div></div></div></div>
<div class="border rounded-xl p-4"><div class="flex justify-between"><span class="w-8 h-8 bg-red-500 text-white rounded-full grid place-items-center font-bold text-xs">C</span><span class="text-[10px] bg-green-100 text-green-700 px-2 py-1 rounded-full">Enabled</span></div><p class="font-bold mt-3">ClubKonnect</p><p class="text-lg font-bold text-[#0f8a4f]">₦120,500</p><div class="mt-2"><p class="text-[11px] flex justify-between"><span>Success</span><span>97.2%</span></p><div class="h-1.5 bg-gray-100 rounded-full mt-1"><div class="h-full bg-[#0f8a4f] rounded-full" style="width:97%"></div></div></div></div>
<div class="border rounded-xl p-4"><div class="flex justify-between"><span class="w-8 h-8 bg-green-600 text-white rounded-full grid place-items-center font-bold text-xs">D</span><span class="text-[10px] bg-green-100 text-green-700 px-2 py-1 rounded-full">Enabled</span></div><p class="font-bold mt-3">DataHouse</p><p class="text-lg font-bold text-[#0f8a4f]">₦89,300</p><div class="mt-2"><p class="text-[11px] flex justify-between"><span>Success</span><span>99.1%</span></p><div class="h-1.5 bg-gray-100 rounded-full mt-1"><div class="h-full bg-[#0f8a4f] rounded-full" style="width:99%"></div></div></div></div>
<div class="border rounded-xl p-4 border-yellow-200 bg-yellow-50/50"><div class="flex justify-between"><span class="w-8 h-8 bg-gray-800 text-white rounded-full grid place-items-center font-bold text-xs">E</span><span class="text-[10px] bg-yellow-100 text-yellow-700 px-2 py-1 rounded-full">Low Balance</span></div><p class="font-bold mt-3">EasyAccess</p><p class="text-lg font-bold text-[#0f8a4f]">₦12,400</p><div class="mt-2"><p class="text-[11px] flex justify-between"><span>Success</span><span>96.8%</span></p><div class="h-1.5 bg-gray-100 rounded-full mt-1"><div class="h-full bg-yellow-500 rounded-full" style="width:96%"></div></div></div></div>
</div>
</div>

<!-- Success Rate Chart -->
<div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4">
<div class="lg:col-span-2 bg-white rounded-xl border shadow-sm p-5">
<h3 class="font-bold mb-4">API Provider Success Rate (24h)</h3>
<div class="space-y-3">
<div class="flex items-center gap-3"><span class="text-xs w-24">VTU.ng</span><div class="flex-1 h-3 bg-gray-100 rounded-full"><div class="h-full bg-[#0f8a4f] rounded-full" style="width:98.5%"></div></div><span class="text-xs font-bold">98.5%</span></div>
<div class="flex items-center gap-3"><span class="text-xs w-24">ClubKonnect</span><div class="flex-1 h-3 bg-gray-100 rounded-full"><div class="h-full bg-[#0f8a4f] rounded-full" style="width:97.2%"></div></div><span class="text-xs font-bold">97.2%</span></div>
<div class="flex items-center gap-3"><span class="text-xs w-24">DataHouse</span><div class="flex-1 h-3 bg-gray-100 rounded-full"><div class="h-full bg-[#12a15a] rounded-full" style="width:99.1%"></div></div><span class="text-xs font-bold">99.1%</span></div>
<div class="flex items-center gap-3"><span class="text-xs w-24">EasyAccess</span><div class="flex-1 h-3 bg-gray-100 rounded-full"><div class="h-full bg-yellow-500 rounded-full" style="width:96.8%"></div></div><span class="text-xs font-bold">96.8%</span></div>
<div class="flex items-center gap-3"><span class="text-xs w-24">UWS</span><div class="flex-1 h-3 bg-gray-100 rounded-full"><div class="h-full bg-red-400 rounded-full" style="width:94.2%"></div></div><span class="text-xs font-bold">94.2%</span></div>
</div>
<table class="w-full mt-6 text-xs"><thead><tr class="text-gray-400 border-b"><th class="text-left py-2">Provider</th><th>Total</th><th>Success</th><th>Failed</th><th>Avg Resp</th></tr></thead><tbody>
<tr class="border-b"><td class="py-2">VTU.ng</td><td>1,240</td><td class="text-green-600">98.5%</td><td>1.5%</td><td>1.2s</td></tr>
<tr class="border-b"><td class="py-2">ClubKonnect</td><td>890</td><td class="text-green-600">97.2%</td><td>2.8%</td><td>1.8s</td></tr>
<tr class="border-b"><td class="py-2">DataHouse</td><td>560</td><td class="text-green-600">99.1%</td><td>0.9%</td><td>0.9s</td></tr>
</tbody></table>
</div>
<div class="bg-white rounded-xl border shadow-sm p-5">
<h3 class="font-bold mb-4">Revenue by Service</h3>
<div class="space-y-2 text-xs"><div class="flex gap-2 items-center"><div class="w-3 h-3 bg-[#0f8a4f] rounded-full"></div>Airtime 35%</div><div class="flex gap-2 items-center"><div class="w-3 h-3 bg-[#12a15a] rounded-full"></div>Data 40%</div><div class="flex gap-2 items-center"><div class="w-3 h-3 bg-[#0a5c32] rounded-full"></div>Bills 15%</div><div class="flex gap-2 items-center"><div class="w-3 h-3 bg-[#c8f0d9] rounded-full"></div>TV 10%</div></div>
</div>
</div>

<!-- New Users + High Transaction -->
<div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-4">
<div class="bg-white rounded-xl border shadow-sm p-5">
<h3 class="font-bold mb-4">New Users (Last 7 days) - 87</h3>
<div class="space-y-3 text-sm">
<div class="flex justify-between items-center"><div class="flex gap-2 items-center"><div class="w-8 h-8 bg-gray-200 rounded-full grid place-items-center text-xs">TA</div><div><p class="font-medium text-xs">Tolu A.</p><p class="text-[11px] text-gray-500">0803 • 2h ago</p></div></div><button class="text-xs bg-green-100 text-green-700 px-3 py-1 rounded-full">Verify</button></div>
<div class="flex justify-between items-center"><div class="flex gap-2 items-center"><div class="w-8 h-8 bg-gray-200 rounded-full grid place-items-center text-xs">EJ</div><div><p class="font-medium text-xs">Emeka J.</p><p class="text-[11px] text-gray-500">0812 • 5h ago</p></div></div><button class="text-xs bg-green-100 text-green-700 px-3 py-1 rounded-full">Verify</button></div>
<div class="flex justify-between items-center"><div class="flex gap-2 items-center"><div class="w-8 h-8 bg-gray-200 rounded-full grid place-items-center text-xs">SK</div><div><p class="font-medium text-xs">Sarah K.</p><p class="text-[11px] text-gray-500">0701 • 8h ago</p></div></div><button class="text-xs bg-green-100 text-green-700 px-3 py-1 rounded-full">Verify</button></div>
</div>
</div>
<div class="bg-white rounded-xl border shadow-sm p-5">
<h3 class="font-bold mb-4">High-Value Users (Top Spenders)</h3>
<div class="space-y-3 text-sm">
<div class="flex justify-between items-center"><div class="flex gap-2 items-center"><div class="w-8 h-8 bg-yellow-400 rounded-full grid place-items-center text-xs font-bold">JW</div><div><p class="font-medium text-xs">James W. <span class="bg-yellow-100 text-yellow-700 text-[9px] px-1 rounded">Gold</span></p><p class="text-[11px] text-gray-500">₦450k • 234 txns</p></div></div><button class="text-xs border px-3 py-1 rounded-full">View</button></div>
<div class="flex justify-between items-center"><div class="flex gap-2 items-center"><div class="w-8 h-8 bg-gray-300 rounded-full grid place-items-center text-xs font-bold">FB</div><div><p class="font-medium text-xs">Fatima B. <span class="bg-purple-100 text-purple-700 text-[9px] px-1 rounded">Platinum</span></p><p class="text-[11px] text-gray-500">₦380k • 189 txns</p></div></div><button class="text-xs border px-3 py-1 rounded-full">View</button></div>
<div class="flex justify-between items-center"><div class="flex gap-2 items-center"><div class="w-8 h-8 bg-green-200 rounded-full grid place-items-center text-xs font-bold">MA</div><div><p class="font-medium text-xs">Musa A. <span class="bg-yellow-100 text-yellow-700 text-[9px] px-1 rounded">Gold</span></p><p class="text-[11px] text-gray-500">₦320k • 156 txns</p></div></div><button class="text-xs border px-3 py-1 rounded-full">View</button></div>
</div>
</div>
</div>
</div>

<script>
function switchView(v){
document.getElementById('view-user').classList.toggle('hidden', v!=='user');
document.getElementById('view-admin').classList.toggle('hidden', v!=='admin');
document.getElementById('btn-user').className = v==='user' ? 'px-5 py-2 rounded-full text-[13px] font-semibold bg-[#0f8a4f] text-white shadow' : 'px-5 py-2 rounded-full text-[13px] font-semibold text-[#6b7280]';
document.getElementById('btn-admin').className = v==='admin' ? 'px-5 py-2 rounded-full text-[13px] font-semibold bg-[#0a2e1c] text-white shadow' : 'px-5 py-2 rounded-full text-[13px] font-semibold text-[#6b7280]';
}
function toggleBal(){alert('Toggle balance visibility')}
function toggleDark(){document.body.classList.toggle('bg-[#0f1f17]');}
</script>
</body>
</html>
