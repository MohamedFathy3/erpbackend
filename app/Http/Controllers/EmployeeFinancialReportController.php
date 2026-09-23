<?php
namespace App\Http\Controllers;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\SalesInvoice;
use App\Models\InvoicePayment;
use App\Models\SalesInvoicePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
class EmployeeFinancialReportController extends Controller
{
 public function show(Request $request, Employee $employee)
 {
  $viewer=$request->user(); if($viewer instanceof Employee && $viewer->branch_id && (int)$viewer->branch_id !== (int)$employee->branch_id) return response()->json(['message'=>'You are not allowed to access another branch employee'],403);
  $from=$request->input('from')?Carbon::parse($request->input('from'))->startOfDay():null; $to=$request->input('to')?Carbon::parse($request->input('to'))->endOfDay():null;
  $pos=Invoice::with(['customer:id,name,branch_id','items.product:id,name,sku'])->where('cashier_id',$employee->id)->where('branch_id',$employee->branch_id); $sales=SalesInvoice::with(['customer:id,name,branch_id','items.product:id,name,sku'])->where('branch_id',$employee->branch_id)->whereHas('payments',fn($q)=>$q->where('employee_id',$employee->id));
  if($from){$pos->where('created_at','>=',$from);$sales->where('invoice_date','>=',$from->toDateString());} if($to){$pos->where('created_at','<=',$to);$sales->where('invoice_date','<=',$to->toDateString());}
  $posInvoices=$pos->get(); $salesInvoices=$sales->get(); $pp=InvoicePayment::with('invoice.customer')->where('employee_id',$employee->id)->whereHas('invoice',fn($q)=>$q->where('branch_id',$employee->branch_id)); $sp=SalesInvoicePayment::with('invoice.customer')->where('employee_id',$employee->id)->whereHas('invoice',fn($q)=>$q->where('branch_id',$employee->branch_id));
  if($from){$pp->where('created_at','>=',$from);$sp->where('created_at','>=',$from);} if($to){$pp->where('created_at','<=',$to);$sp->where('created_at','<=',$to);}
  $collections=$pp->get()->map(fn($x)=>['id'=>$x->id,'source'=>'pos','amount'=>(float)$x->amount,'method'=>$x->method,'date'=>$x->created_at?->toDateString(),'customer'=>$x->invoice?->customer?->only(['id','name']),'invoice_id'=>$x->invoice_id])->concat($sp->get()->map(fn($x)=>['id'=>$x->id,'source'=>'sales','amount'=>(float)$x->amount,'method'=>$x->payment_method,'date'=>$x->created_at?->toDateString(),'customer'=>$x->invoice?->customer?->only(['id','name']),'invoice_id'=>$x->sales_invoice_id]))->sortByDesc('date')->values();
  $items=fn($i)=>$i->items->map(fn($x)=>['product'=>$x->product?->only(['id','name','sku']),'quantity'=>(float)$x->quantity,'total'=>(float)$x->total])->values(); $invoices=$posInvoices->map(fn($i)=>['id'=>$i->id,'source'=>'pos','number'=>$i->invoice_number,'date'=>$i->created_at?->toDateString(),'customer'=>$i->customer?->only(['id','name']),'total'=>(float)$i->total_amount,'paid'=>(float)$i->paid_amount,'remaining'=>(float)$i->remaining_amount,'items'=>$items($i)])->concat($salesInvoices->map(fn($i)=>['id'=>$i->id,'source'=>'sales','number'=>$i->invoice_number,'date'=>$i->invoice_date?->toDateString(),'customer'=>$i->customer?->only(['id','name']),'total'=>(float)($i->net_total??$i->total_amount),'paid'=>(float)($i->paid_amount??0),'remaining'=>max(0,(float)($i->net_total??$i->total_amount)-(float)($i->paid_amount??0)),'items'=>$items($i)]))->sortByDesc('date')->values(); $customers=$invoices->pluck('customer')->filter()->unique('id')->values();
  return response()->json(['status'=>true,'data'=>['employee'=>['id'=>$employee->id,'name'=>$employee->name,'branch_id'=>$employee->branch_id],'summary'=>['invoices_count'=>$invoices->count(),'customers_count'=>$customers->count(),'sales_total'=>(float)$invoices->sum('total'),'paid_total'=>(float)$invoices->sum('paid'),'remaining_total'=>(float)$invoices->sum('remaining'),'collections_total'=>(float)$collections->sum('amount'),'collections_count'=>$collections->count()],'customers'=>$customers,'invoices'=>$invoices,'collections'=>$collections]]);
 }
}
