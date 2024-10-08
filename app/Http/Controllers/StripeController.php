<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Models\Policy;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Stripe\Charge;
use Stripe\Refund;
use Stripe\Stripe;

class StripeController extends Controller
{
    public function index()
    {
        return success(null, 'Payment Cancelled');
    }

    public function checkout(Reservation $reservation)
    {
        if ($reservation->status === 'Cancelled') {
            return error('some thing went wrong', 'this reservation cancelled before', 422);
        } else if ($reservation->status === 'Ended') {
            return error('some thing went wrong', 'this reservation ended', 422);
        }
        $count = 0;
        $price = 0;
        $discount = 0;

        $count = count($reservation->flightSeats);

        $price += $reservation->flight->price * $count;

        if ($reservation->round_trip) {
            $price += $reservation->roundFlight->price * $count;
        }

        foreach ($reservation->flight->offers as $offer) {
            if ($offer->start_date <= $reservation->time->day->departure_date && $offer->end_date >= $reservation->time->day->departure_date) {
                $price = $price - $price * $offer->discount / 100;
                break;
            }
        }
        $reservation_date = $reservation->created_at;
        $departure_date = $reservation->time->day->departure_date;

        $days = $reservation_date->diffInDays($departure_date);
        if ($days >= 14 && $days < 30)
            while ($days >= 14) {
                $discount = Policy::where('after two weeks')->first()->value;
                $days -= 14;
            }
        if ($days >= 30) {
            while ($days >= 30) {
                $discount += Policy::where('after month')->first()->value;
                $days -= 30;
            }
        }
        $price = $price - $price * $discount / 100;
        \Stripe\Stripe::setApiKey(config('stripe.sk'));
        $session = \Stripe\Checkout\Session::create([
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => 'Send me money!!!'
                        ],
                        'unit_amount' => $price * 100, //5.00
                    ],
                    'quantity' => 1,
                ],
            ],
            'mode' => 'payment',
            'success_url' => route('success', $reservation->reservation_id),
            'cancel_url' => route('index'),
        ]);

        return redirect()->away($session->url);
    }

    public function success(Reservation $reservation)
    {
        $user = Auth::guard('user')->user();
        $reservation->update([
            'status' => 'Confirmed'
        ]);
        Log::create([
            'message' => 'Passenger ' . $user->passenger->travelRequirement->first_name . ' ' . $user->passenger->travelRequirement->last_name . ' confirmed his reservation',
            'type' => 'insert',
        ]);
        return success($reservation, 'Payment Completed Successfully');
    }

    //Cancel Reservation Function
    public function cancelReservation(Reservation $reservation)
    {
        $discount = 0;
        $user = Auth::guard('user')->user();
        if ($reservation->status === 'Cancelled') {
            return error('some thing went wrong', 'this reservation already cancelled', 422);
        } else if ($reservation->status === 'Pending') {
            foreach ($reservation->flightSeats as $flightSeat) {
                $flightSeat->delete();
            }
            $reservation->update([
                'status' => 'Cancelled'
            ]);
            Log::create([
                'message' => 'Passenger ' . $user->passenger->travelRequirement->first_name . ' ' . $user->passenger->travelRequirement->last_name . ' cancelled his reservation',
                'type' => 'insert',
            ]);
            return success(null, 'this reservation cancelled successfully');
        }
        if (Carbon::now() > $reservation->time->day->departure_date) {
            return error('some thing went wrong', 'you cannot cancel this reservation now');
        }
        $cost = 0;
        $companions_count = count(explode(',', $reservation->have_companions));
        if ($reservation->is_traveling) {
            $companions_count++;
        }
        $cost += $reservation->flight->price * $companions_count;
        if ($reservation->round_trip) {
            $cost += $reservation->roundFlight->price * $companions_count;
        }
        foreach ($reservation->flight->offers as $offer) {
            if ($offer->start_date <= $reservation->time->day->departure_date && $offer->end_date >= $reservation->time->day->departure_date) {
                $cost = $cost - $cost * $offer->discount / 100;
                break;
            }
        }

        $reservation_date = $reservation->created_at;
        $departure_date = $reservation->time->day->departure_date;
        $days = $reservation_date->diffInDays($departure_date);
        $days_before_cancel = Carbon::parse($departure_date)->diffInDays(Carbon::now());
        if ($days_before_cancel == 1) {
            $cost = $cost - $cost * Policy::where('policy_name', 'cancelation before a day')->fist()->value / 100;
        } else if ($days_before_cancel > 1 && $days_before_cancel <= 7) {
            $cost = $cost - $cost * Policy::where('policy_name', 'cancelation before a week')->fist()->value / 100;
        } else if ($days_before_cancel > 7) {
            $cost = $cost - $cost * Policy::where('policy_name', 'cancelation before more than a week')->fist()->value / 100;
        }
        if ($days >= 14 && $days < 30)
            while ($days >= 14) {
                $discount = Policy::where('after two weeks')->first()->value;
                $days -= 14;
            }
        if ($days >= 30) {
            while ($days >= 30) {
                $discount += Policy::where('after month')->first()->value;
                $days -= 30;
            }
        }
        $cost = $cost - $cost * $discount / 100;
        Stripe::setApiKey(config('stripe.sk'));
        $charge = Charge::create([
            'amount' => $cost,
            'currency' => 'usd',
            'source' => 'tok_visa',
            'description' => 'Test Charge',
        ]);
        $chargeId = $charge->id;
        $refund = Refund::create([
            'charge' => $chargeId,
            'amount' => $cost,
        ]);

        if ($refund->status == 'succeeded') {
            $reservation->update([
                'status' => 'Cancelled'
            ]);
            foreach ($reservation->flightSeats as $flightSeat) {
                $flightSeat->delete();
            }
            Log::create([
                'message' => 'Passenger ' . $user->passenger->travelRequirement->first_name . ' ' . $user->passenger->travelRequirement->last_name . ' cancelled his reservation and return to him ' . $cost . '$',
                'type' => 'insert',
            ]);
            return success(null, 'this reservation cancelled successfully and return to you ' . $cost . '$');
        } else {
            return error('some thing went wrong', 'cancel faild', 422);
        }
    }
}
