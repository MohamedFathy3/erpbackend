<?php
namespace App\Http\Controllers;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\SalesInvoice;
use App\Models\InvoiceTransferRequest;
use App\Models\SalesRepresentative;
use App\Services\InvoiceTransferPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
class InvoiceTransferRequestController extends Controller {
 public function index(Request $request){$actor=$request->user();$q=InvoiceTransferRequest::with(['fromEmployee:id,name','toEmployee:id,name','approver:id,name','invoice.items.product:id,name,cost','invoice.customer:id,name','salesInvoice.items.product:id,name,cost','salesInvoice.customer:id,name'])->latest();if($actor instanceof Employee&&!$actor->super_admin&&!str_contains(strtolower((string)$actor->role?->name),'admin'))$q->where(function($x)use($actor){$x->where('from_employee_id',$actor->id)->orWhere('to_employee_id',$actor->id);});$page=$q->paginate($request->integer('per_page',30));$page->getCollection()->transform(function($request){$invoice=$request->invoice_type==='pos'?$request->invoice:$request->salesInvoice;if($invoice){$cost=(float)$invoice->items->sum(fn($item)=>(float)($item->product?->cost??0)*(float)$item->quantity);$total=(float)($invoice->net_total??$invoice->total_amount??$invoice->total??0);$request->invoice_details=['id'=>$invoice->id,'invoice_number'=>$invoice->invoice_number,'date'=>$invoice->invoice_date??$invoice->created_at,'customer'=>$invoice->customer?->only(['id','name']),'total'=>round($total,2),'cost'=>round($cost,2),'profit'=>round($total-$cost,2),'items'=>$invoice->items->map(fn($item)=>['product_name'=>$item->product?->name,'quantity'=>(float)$item->quantity,'price'=>(float)($item->price??0),'cost'=>round((float)($item->product?->cost??0),2),'total'=>round((float)($item->total??(($item->price??0)*$item->quantity)),2)])->values()];}return $request;});return response()->json(['data'=>$page]);}
 public function store(Request $request)
 {
	 $actor = $request->user();
	 abort_unless($actor instanceof Employee || $actor instanceof Admin, 403);
	 $data = $request->validate([
		 'invoice_type' => 'required|in:pos,sales',
		 'invoice_id' => 'required|integer',
		 'to_employee_id' => 'required|exists:employees,id',
		 'from_employee_id' => 'nullable|exists:employees,id',
		 'note' => 'nullable|string|max:1000',
	 ]);
	 abort_unless(
		 SalesRepresentative::query()->where('employee_id', $data['to_employee_id'])->where('active', true)->exists(),
		 422,
		 'الموظف المستلم غير مرتبط بمندوب مبيعات نشط.'
	 );

	 $model = $data['invoice_type'] === 'pos'
		 ? Invoice::with(['salesRepresentative', 'shift'])->findOrFail($data['invoice_id'])
		 : SalesInvoice::with('salesRepresentative')->findOrFail($data['invoice_id']);
	 $from = (int) ($data['invoice_type'] === 'pos'
		 ? $model->salesRepresentative?->employee_id
		 : $model->sales_representative?->employee_id);
	 $isAdmin = $actor instanceof Admin || (bool) ($actor->super_admin ?? false)
		 || str_contains(strtolower((string) $actor->role?->name), 'admin');

	 if ($from <= 0) {
		 abort_unless($isAdmin, 403, 'تحويل فاتورة غير مرتبطة بمندوب متاح للمدير فقط.');
	 } else {
		 abort_unless(
			 !($data['from_employee_id'] ?? null) || (int) $data['from_employee_id'] === $from,
			 403,
			 'لا يمكن تحويل فاتورة لا تخص مندوب المبيعات الذي سجل الدخول.'
		 );
		 abort_unless($from !== (int) $data['to_employee_id'], 422, 'لا يمكن تحويل الفاتورة لنفس البائع.');
	 }

	 $shiftId = $data['invoice_type'] === 'pos' ? $model->cashier_shift_id : null;
	 if ($shiftId && $model->shift?->status === 'closed') {
		 return response()->json(['message' => 'لا يمكن تحويل فاتورة من وردية مغلقة.'], 422);
	 }

	 $requestModel = InvoiceTransferRequest::create([
		 'invoice_type' => $data['invoice_type'],
		 'invoice_id' => $model->id,
		 'from_employee_id' => $from > 0 ? $from : null,
		 'to_employee_id' => $data['to_employee_id'],
		 'cashier_shift_id' => $shiftId,
		 'note' => $data['note'] ?? null,
	 ]);

	 if ($actor instanceof Admin) {
		 return $this->approve($request, $requestModel);
	 }

	 return response()->json([
		 'data' => $requestModel->load(['fromEmployee', 'toEmployee']),
	 ], Response::HTTP_CREATED);
 }
 public function approve(Request $request,InvoiceTransferRequest $transfer){$actor=$request->user();abort_unless($actor instanceof Admin&&($actor->super_admin||true),403,'اعتماد تحويل الفاتورة متاح للمدير فقط.');$data=$request->validate(['note'=>'nullable|string|max:1000']);$result=DB::transaction(function()use($transfer,$actor,$data){$transfer=InvoiceTransferRequest::lockForUpdate()->findOrFail($transfer->id);if($transfer->status!=='pending')abort(422,'تمت معالجة الطلب مسبقًا.');if($transfer->cashier_shift_id&&$transfer->shift?->status==='closed'){$transfer->update(['status'=>'cancelled','approved_by'=>$actor->id,'approved_at'=>now(),'note'=>$data['note']??$transfer->note]);return $transfer;}if($transfer->invoice_type==='pos'){$invoice=Invoice::with('salesRepresentative')->lockForUpdate()->findOrFail($transfer->invoice_id);$targetRep=\App\Models\SalesRepresentative::where('employee_id',$transfer->to_employee_id)->where('active',true)->first();if(!$targetRep)abort(422,'الموظف المستلم ليس مرتبطًا بمندوب مبيعات.');$invoice->update(['sales_representative_id'=>$targetRep->id]);}else{$invoice=SalesInvoice::lockForUpdate()->findOrFail($transfer->invoice_id);$rep=\App\Models\SalesRepresentative::where('employee_id',$transfer->to_employee_id)->first();if(!$rep)abort(422,'الموظف المستلم ليس مسجلًا كبائع.');$invoice->update(['sales_representative_id'=>$rep->id]);}$amount=(float)($invoice->total_amount ?? $invoice->net_total ?? $invoice->total ?? 0);app(InvoiceTransferPostingService::class)->post($transfer,$amount);$transfer->update(['status'=>'approved','approved_by'=>$actor->id,'approved_at'=>now(),'note'=>$data['note']??$transfer->note]);return $transfer;});return response()->json(['data'=>$result->load(['fromEmployee','toEmployee','approver'])]);}
 public function reject(Request $request,InvoiceTransferRequest $transfer){$actor=$request->user();abort_unless($actor instanceof Admin,403);$transfer->update(['status'=>'rejected','approved_by'=>$actor->id,'approved_at'=>now(),'note'=>$request->input('note',$transfer->note)]);return response()->json(['data'=>$transfer]);}
}
