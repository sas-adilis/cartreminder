<table width="100%" border="0" cellspacing="0" cellpadding="0">
	{foreach from=$products_cart item='cartProduct' name=cartProduct}
	<tr>
		<td width="146">
			<a href="{$link->getProductLink($cartProduct.id_product, $cartProduct.link_rewrite, $cartProduct.category)}" title="{$cartProduct.name|htmlspecialchars}">
				<img src="{$link->getImageLink($cartProduct.link_rewrite, $cartProduct.id_image, 'small_default')}" alt="{$cartProduct.name|htmlspecialchars}" border="0" />
			</a>
		</td>
		<td>
			<p style="color: #333; ">
				<a style="color: #2F3131; font-weight: bold; text-decoration: none" href="{$link->getProductLink($cartProduct.id_product, $cartProduct.link_rewrite, $cartProduct.category)}">{$cartProduct.name|escape:'htmlall':'UTF-8'}</a>
			</p>
		</td>
	</tr>
	{/foreach}
</table>