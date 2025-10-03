<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\OrdersExport;

class ThongKeController extends Controller
{
    public function index()
    {
        // Chỉ admin mới được vào
        if (auth()->user()->role !== 'admin') {
            return redirect()->route('home')->with('error', 'Bạn không có quyền truy cập!');
        }

        /** =========================
         *  THỐNG KÊ TỔNG QUAN
         *  ========================= */
        $totalUsers    = User::count();
        $totalProducts = Product::count();
        $totalOrders   = Order::count();
        $totalRevenue  = Order::where('status', 'confirmed')->sum('total');

        $orderStatus = [
            'processing' => Order::where('status', 'processing')->count(),
            'cancelled'  => Order::where('status', 'cancelled')->count(),
            'confirmed'  => Order::where('status', 'confirmed')->count(),
        ];

        /** =========================
         *  THEO NGÀY
         *  ========================= */
        $today            = Carbon::today();
        $ordersDay        = Order::whereDate('created_at', $today)->get();
        $ordersCountDay   = $ordersDay->count();
        $revenueDay       = $ordersDay->where('status', 'confirmed')->sum('total');

        /** =========================
         *  THEO TUẦN
         *  ========================= */
        $startWeek        = Carbon::now()->startOfWeek();
        $endWeek          = Carbon::now()->endOfWeek();
        $ordersWeek       = Order::whereBetween('created_at', [$startWeek, $endWeek])->get();
        $ordersCountWeek  = $ordersWeek->count();
        $revenueWeek      = $ordersWeek->where('status', 'confirmed')->sum('total');

        // Biểu đồ doanh thu theo ngày trong tuần
        $revenueByDayWeek = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $startWeek->copy()->addDays($i);
            $revenueByDayWeek[$day->format('D')] = Order::whereDate('created_at', $day)
                ->where('status', 'confirmed')
                ->sum('total');
        }

        /** =========================
         *  THEO THÁNG
         *  ========================= */
        $startMonth       = Carbon::now()->startOfMonth();
        $endMonth         = Carbon::now()->endOfMonth();
        $ordersMonth      = Order::whereBetween('created_at', [$startMonth, $endMonth])->get();
        $ordersCountMonth = $ordersMonth->count();
        $revenueMonth     = $ordersMonth->where('status', 'confirmed')->sum('total');

        // Biểu đồ doanh thu theo tuần trong tháng
        $revenueByWeekMonth = [];
        $weekStart = $startMonth->copy();
        $index     = 1;
        while ($weekStart->lt($endMonth)) {
            $weekEnd = $weekStart->copy()->endOfWeek()->min($endMonth);
            $revenueByWeekMonth["Tuần " . $index] = Order::whereBetween('created_at', [$weekStart, $weekEnd])
                ->where('status', 'confirmed')
                ->sum('total');
            $weekStart = $weekEnd->addDay();
            $index++;
        }

        /** =========================
         *  THEO NĂM
         *  ========================= */
        $startYear       = Carbon::now()->startOfYear();
        $endYear         = Carbon::now()->endOfYear();
        $ordersYear      = Order::whereBetween('created_at', [$startYear, $endYear])->get();
        $ordersCountYear = $ordersYear->count();
        $revenueYear     = $ordersYear->where('status', 'confirmed')->sum('total');

        // Biểu đồ doanh thu theo tháng trong năm
        $revenueByMonthYear = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthStart = Carbon::create(null, $m, 1)->startOfMonth();
            $monthEnd   = $monthStart->copy()->endOfMonth();
            $revenueByMonthYear["Th $m"] = Order::whereBetween('created_at', [$monthStart, $monthEnd])
                ->where('status', 'confirmed')
                ->sum('total');
        }

        return view('admin.thongke.index', compact(
            'totalUsers', 'totalProducts', 'totalOrders', 'totalRevenue', 'orderStatus',
            'ordersCountDay', 'revenueDay',
            'ordersCountWeek', 'revenueWeek', 'revenueByDayWeek',
            'ordersCountMonth', 'revenueMonth', 'revenueByWeekMonth',
            'ordersCountYear', 'revenueYear', 'revenueByMonthYear'
        ));
    }

    public function exportExcel($type)
    {
        return Excel::download(
            new OrdersExport($type),
            'thongke_' . $type . '_' . Carbon::now()->format('Y-m-d') . '.xlsx'
        );
    }
}
