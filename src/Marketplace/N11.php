<?php

namespace salyangoz\pazaryeriparasut\Marketplace;

use salyangoz\pazaryeriparasut;
use Exception;
use Illuminate\Support\Facades\Log;
use salyangoz\pazaryeriparasut\Models\Order;
use Carbon\Carbon;
use salyangoz\pazaryeriparasut\Services\Pull;

class N11 extends Marketplace
{
    private $n11;
    private $marketplace = "N11";

    public function __construct(array $config)
    {

        parent::__construct($config);

        $n11Params = ['appKey'       =>  array_get($config,'n11_app_key'),
                      'appSecret'    =>  array_get($config,'n11_app_secret'),
                      'baseUrl'      =>  array_get($config,'n11_base_url')];

        $this->n11  = new pazaryeriparasut\Library\N11($n11Params);

    }

    /**
     * n11 son 1 gün içerisindeki Siparişlerini getirir
     * @return array
     */
    protected function sales()
    {

            $sales = [];

            $allOrders		=	array();
            $currentPage	=	-1;
			$totalPage = 0;

            try
            {

                do{

                    $currentPage++;

                    $orderList  = $this->n11->DetailedOrderList(
                        [
                            "productId"=>'',
                            "status"=> 'Approved',
                            "buyerName"=> '',
                            "orderNumber"=> '',
                            "productSellerCode" =>'',
                            "recipient"=> '',
                            "period"=>[
								"startDate"=> date_create("-3 days")->format("d/m/Y"),
								"endDate"=> date_create("now")->format("d/m/Y")
                            ]
                        ],
                        [
                            "currentPage"=>$currentPage
                        ]
                    );

                    $this->n11->checkResponse($orderList);

                    if($orderList->pagingData->totalCount == 0)
                    {
                        continue;
                    }

                    $totalPage	=	$orderList->pagingData->pageCount;

                    if(!isset($orderList->orderList->order))
                    {
                        continue;
                    }
					
					if(is_object($orderList->orderList->order)) {
						
						$orderList->orderList->order = array($orderList->orderList->order);
						
					}

                    foreach($orderList->orderList->order as $order2)
                    {
						$orderDetail  = $this->n11->OrderDetail([
							"id" => $order2->id
						]);
							
                        $orderCount = Order::where('marketplace', $this->marketplace)->where('order_id', $order2->orderNumber)->count();

                        if($orderCount == 0)
                        {
							Log::info("order id: ". $order2->id);
                            sleep(1);
                            $orderDetail  = $this->n11->OrderDetail([
                                "id" => $order2->id
                            ]);

                            $this->processSale($orderDetail->orderDetail);
                        }
                    }

                    sleep(7);

                }while($currentPage<$totalPage-1);

                return $sales;


            }
            catch (Exception $e)
            {
                Log::error($e->getMessage());
                Log::error($e->getTraceAsString());
				echo $e->getMessage();
				echo $e->getTraceAsString();
            }


            return ;

    }

    protected function processSale($sale)
    {

        $orderCount = Order::where('marketplace',$this->marketplace)->where('order_id',$sale->orderNumber)->count();

        if($orderCount > 0)
        {
            return;
        }

        /** Eğer sipariş tutarı 0 tl ise atıyor */
        if($sale->billingTemplate->dueAmount == 0 )
            return;

        $contactType    =   $sale->buyer->taxId ? 'Company':'Customer';
        $taxNumber      =   $sale->buyer->taxId;
        $taxOffice      =   $sale->buyer->taxOffice;
        $tc             =   self::fillTc($sale->buyer->tcId);
        $name2          =   isset($sale->buyer->fullName) ? $sale->buyer->fullName : $sale->billingAddress->fullname;
		
		if(strlen($name2) == 0 && isset($sale->billingAddress)){
			if(isset($sale->billingAddress->fullName)) {
				$name2 = $sale->billingAddress->fullName;
			}
		}

        $pull   =   new Pull($this->marketplace);
        $pull->createCustomer($contactType, $sale->buyer->id, $name2,
                                $sale->billingAddress->address, $taxNumber, $taxOffice, $sale->billingAddress->city,
                                $sale->billingAddress->district, $sale->billingAddress->gsm, $sale->buyer->email, $tc, $sale->billingAddress->fullName);

        $invoiceDescription = $this->getInvoiceDescription($sale);

        $createdAt = Carbon::createFromFormat('d/m/Y H:i',$sale->createDate);

        $pull->createOrder($sale->orderNumber, $sale->billingTemplate->sellerInvoiceAmount - $sale->billingTemplate->totalServiceItemOriginalPrice, "N11 #" . $sale->orderNumber, $createdAt);

        foreach ($sale->itemList as $item)
        {
            if(!is_array($item)){
                $items[] = $item;
            }
            else
            {
                $items = $item;
            }

            foreach ($items as $i)
            {
                $pull->addProduct($i->productName,$i->productId,$i->quantity,($i->sellerInvoiceAmount / $i->quantity));
            }

        }

    }

    private function getInvoiceDescription($sale)
    {
        $items  =   [];
        foreach ($sale->itemList as $item1)
        {

            if(!is_array($item1)){
                $items[] = $item1;
            }
            else
            {
                $items  =   $item1;
            }

            $invoiceDescription =   "";

            $total  =   0;

            foreach ($items as $i)
            {
                $invoiceDescription.=$i->productName." ";
                $total+=$i->dueAmount/$i->quantity;
            }

        }

        return $invoiceDescription;
    }

    public function pull()
    {
        $this->sales();
    }

}
