<?php

namespace Database\Seeders;

use App\Models\ChatbotFaq;
use Illuminate\Database\Seeder;

/**
 * Starter FAQs taken from the spec. Admins edit them under Admin → FAQ. Placeholders such as
 * {pickup_days} are filled with the live settings when answered. Only inserts when the table is empty.
 */
class ChatbotFaqSeeder extends Seeder
{
    public function run(): void
    {
        if (ChatbotFaq::query()->exists()) {
            return;
        }

        $faqs = [
            ['How does reserving work?', 'Pick your items, pay a {downpayment_percent}% downpayment online with GCash or card, then pick up at the shop and pay the balance there. Unpaid reservations are released after {pending_minutes} minutes.', 'reserve, reservation, how, order, mag order, paano, magpareserve'],
            ['How much is the downpayment?', 'The downpayment is {downpayment_percent}% of your total. You pay the rest at the counter when you pick up.', 'downpayment, down payment, deposit, magkano, how much, balance'],
            ['How can I pay?', 'Online downpayments use PayMongo: GCash or credit/debit card. The balance is paid in cash at the counter. We do not accept cash downpayments.', 'pay, payment, gcash, card, credit card, debit, paymongo, bayad, cash'],
            ['When do I need to pick up?', 'Please pick up within {pickup_days} days after your downpayment is confirmed. Your deadline is shown on your reservation page and in your confirmation email.', 'pickup, pick up, deadline, when, kailan, kunin, claim'],
            ['Can I cancel? Will I get a refund?', 'You can cancel for free (full refund of your downpayment) until we start preparing your order. Once it is marked Processing, it can no longer be cancelled. GCash refunds usually arrive within a day; card refunds can take up to 30 days.', 'cancel, refund, cancellation, ibalik, refund po, money back'],
            ['What does "Processing" mean?', 'Processing means we have started preparing your order. From then on it cannot be cancelled online. Please pick up before your deadline.', 'processing, preparing, status, being prepared'],
            ['What if I miss my pickup deadline?', 'If you do not pick up in time, the reservation ends, the items go back on sale, and only {forfeit_refund_percent}% of your downpayment is refunded.', 'miss, missed, late, forfeit, forfeited, deadline passed, hindi nakuha'],
            ['Do you deliver?', 'No, we do not deliver or ship. All reservations are picked up at the shop.', 'deliver, delivery, ship, shipping, courier, lalamove, padala'],
            ['What are your shop hours and address?', 'Please check our shop hours and address at the counter or on our Facebook page. (Admin: replace this answer with your hours and address.)', 'hours, open, close, address, location, where, saan, oras'],
            ['What do I need to bring at pickup?', 'Bring your reference code (SWA-…). Only the account holder can collect the order, and for expensive items we will also ask for a valid ID.', 'bring, id, identification, reference, code, collect, claim'],
        ];

        foreach ($faqs as $i => [$question, $answer, $keywords]) {
            ChatbotFaq::query()->create([
                'question' => $question,
                'answer' => $answer,
                'keywords' => $keywords,
                'active' => true,
                'sort_order' => ($i + 1) * 10,
            ]);
        }
    }
}
