{*
 * Banner shown in cart to upsell membership
 * $membership_price
 * $saving
 * $orders_to_breakeven
 * $membership_link
 *}
<div class="memberprime-banner panel">
  <p>
    {l s='Become a Member Prime for %s / year and pay %s less on this cart! You’d earn the fee back in about %d orders.'
       sprintf=[$membership_price, $saving, $orders_to_breakeven] mod='memberprime'}
  </p>
  <a class="btn btn-success" href="{$membership_link}">
      {l s='Get Membership' mod='memberprime'}
  </a>
</div>
<style>
.memberprime-banner {background:#f6f6f6;padding:15px;border:1px solid #ddd;text-align:center;}
</style>
