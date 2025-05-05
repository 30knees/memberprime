{*
 * Banner shown in cart to upsell membership
 * $membership_price
 * $saving
 * $orders_to_breakeven
 * $membership_link
 *}
<div class="memberprime-banner panel">
  <p>
    {l s='Become a Prime Member for %s / year and pay %s less on this cart! You’d earn the membership back in about %d such orders.'
       sprintf=[$membership_price, $saving, $orders_to_breakeven] mod='memberprime'}
  </p>
  <a class="btn btn-success" href="{$membership_link}">
      {l s='Join today' mod='memberprime'}
  </a>
</div>
