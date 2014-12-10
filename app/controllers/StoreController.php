<?php

class StoreController extends BaseController{

    public function __construct(){
        parent::__construct();
        $this->beforeFilter('csrf',array('on' =>'post'));
        $this->beforeFilter('auth',array('only'=>array('postAddtoCart','getCart','getRemoveitem')));
        $this->user = Auth::user();

    }

    public function getIndex(){
        return View::make('store.index')
            ->with('products',Product::take(4)->orderBy('created_at','DESC')->get());
    }

    public function getView($url){
        $product = Product::where('url','=',$url)->first();
        return View::make('store.view')
            ->with('product', $product)
            ->with('related', Product::where('category_id','=',$product->category_id)
                                    ->where('url','<>',$url)->get());
    }

    public function getCategory($cat_id){
        return View::make('store.category')
            ->with('products',Product::where('category_id', '=' ,$cat_id)->get())
            ->with('category',Category::find($cat_id));
    }

    public function getSearch(){
        $keyword = Input::get('keyword');

        return View::make('store.search')
            ->with('products', Product::where('title','LIKE', '%'.$keyword.'%')->get())
            ->with('keyword', $keyword);
    }

    public function postAddtocart(){
        $product = Product::find(Input::get('id'));
        $quantity = Input::get('quantity');

     /*   Cart::insert(array(
           'id'         =>$product->id,
            'name'      =>$product->title,
            'price'     =>$product->price,
            'quantity'  =>$quantity,
            'image' =>$product->image
        )); */

        /* Check if theres anything in the basket */
        $checkBasket = Basket::where('user_id','=',Auth::id())
                        ->where('product_id','=',$product->id)
                        ->where('paid','=',0)->first();
        if($checkBasket)
        {
            $checkBasket->quantity = $checkBasket->quantity + $quantity;
            $checkBasket->total_price = $checkBasket->total_price + $product->price;

            $checkBasket->save();


        }else{
            /* Else add into basket */

            $basket = new Basket;

            $basket->user_id = Auth::id();
            $basket->product_id = $product->id;
            $basket->quantity = $quantity;
            $basket->paid = 0;
            $basket->total_price = $product->price;

            $basket->save();
        }


        return Redirect::to('store/cart');

    }

    public function getCart(){
        $basket = DB::table('basket')
                    ->join('products','basket.product_id','=','products.id')
                    ->select('basket.id as bas_id', 'user_id', 'product_id', 'quantity', 'paid', 'products.id', 'category_id',
                        'title', 'url', 'price', 'image_1','total_price')
                    ->where('user_id','=',Auth::id())
                    ->where('paid','=',0)->get();

        $total = DB::table('basket')
            ->where('user_id','=',Auth::id())
            ->where('paid','=',0)
            ->sum('total_price');

        return View::make('store.cart')
            ->with('products',$basket)
            ->with('total',$total);
    }

    public function getRemoveitem($identifier){

        $item = Basket::find($identifier);

        if($item->quantity >= 2)
        {
            $item->quantity = $item->quantity - 1;

            $item->save();

        }elseif($item->quantity == 1)
        {
            $item->delete();
        }


        return Redirect::to('store/cart')
                ->with('success','Product deleted');

    }

    public function getContact(){
        return View::make('store.contact');
    }

    public function postContact(){

        $data = array(
            'name' => Input::get('name'),
            'email' => Input::get('email'),
            'comment' => Input::get('comment')
        );

        Mail::send('emails.contact',$data ,function($message)
        {

           $message->to('yousuf@kruzerdesigns.com')->subject('Contact Form');


        });



        return Redirect::to('store/contact')->with('message','Email has been sent');

       /* echo Input::get('name').'<br>';
        echo Input::get('email').'<br>';
        echo Input::get('message').'<br>';*/
    }


    public function getPages($url){
        $content = Content::where('url','=',$url)->where('nav','=','0')->first();


       return View::make('store.content')
            ->with('content',$content)
            ->with('404','Page not Found');
    }

    public function getCheckout(){
        $email = $this->user->email;
        $basket = DB::table('basket')
            ->join('products','basket.product_id','=','products.id')
            ->select('basket.id as bas_id', 'user_id', 'product_id', 'quantity', 'paid', 'products.id', 'category_id',
                'title', 'url', 'price', 'image_1','total_price')
            ->where('user_id','=',Auth::id())
            ->where('paid','=',0)->get();

        $total = DB::table('basket')
            ->where('user_id','=',Auth::id())
            ->where('paid','=',0)
            ->sum('total_price');

        return View::make('store.checkout')
            ->with('email',$email)
            ->with('products',$basket)
            ->with('total',$total);
    }

    public function postCheckout()
    {



        $token = Input::get('token');
        $price = Input::get('amount');

        $amount = str_replace('.', '', $price);

        try{

            $charge = Stripe_Charge::create(array(
                "amount" => $amount,
                "currency" => "GBP",
                "card" => $token,
                "description" => "order from ".Input::get('delivery_name'). " ;".$this->user->email

            ));

            /* get basket */
            $basket = DB::table('basket')
                ->join('products','basket.product_id','=','products.id')
                ->select('basket.id as bas_id', 'user_id', 'product_id', 'quantity', 'paid', 'products.id', 'category_id',
                    'title', 'url', 'price', 'image_1','total_price')
                ->where('user_id','=',Auth::id())
                ->where('paid','=',0)->get();

            $total = DB::table('basket')
                ->where('user_id','=',Auth::id())
                ->sum('total_price');




            /* Update basket to paid */
            foreach($basket as $b)
            {
                echo $b->bas_id .'<br>';

                $update = Basket::find($b->bas_id);

                $update->paid = 1;
                $update->stripe_token = $charge['id'];

                $update->save();

                /* add basket into orders */
                $orders = new Orders;

                $orders->basket_id = $b->bas_id;
                $orders->stripe_token = $charge['id'];
                $orders->processed = 0;

                $orders->save();

            }

            $user = User::find($this->user->id);

            $user->delivery_name = Input::get('delivery_name');
            $user->address = Input::get('street');
            $user->address_2 = Input::get('street2');
            $user->town = Input::get('town');
            $user->county = Input::get('county');
            $user->postcode = Input::get('postcode');

            $user->save();



        }catch (Stripe_CardError $e){
            $e_json = $e->getJsonBody();
            $error = $e_json['error'];

            return Redirect::to('store/checkout')->with('error',$error);

        }


        return Redirect::to('store/complete');





    }

    public function  getComplete(){

        return View::make('store.complete');

    }



}