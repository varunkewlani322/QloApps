{*
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2015 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*}

{$style_tab}

<table width="100%" id="body" border="0" cellpadding="0" cellspacing="0" style="margin:0;">
	<tr>
		<td colspan="12">
			<table id="summary-tab" width="100%" cellpadding="5">
				<tr>
					<th class="header small" valign="middle">{l s='Order Reference' pdf='true'}</th>
					<th class="header small" valign="middle">{l s='Arrival Date' pdf='true'}</th>
					<th class="header small" valign="middle">{l s='Departure Date' pdf='true'}</th>
					<th class="header small" valign="middle">{l s='Room Number' pdf='true'}</th>
				</tr>
				<tr>
					<td class="center small white">{$order->getUniqReference()|escape:'html':'UTF-8'}</td>
					<td class="center small white">{$registration_form.stay.arrival_date|escape:'html':'UTF-8'}</td>
					<td class="center small white">{$registration_form.stay.departure_date|escape:'html':'UTF-8'}</td>
					<td class="center small white">{$registration_form.stay.room_number|escape:'html':'UTF-8'}</td>
				</tr>
			</table>
		</td>
	</tr>

	<tr>
		<td colspan="12" height="20">&nbsp;</td>
	</tr>

	<tr>
		<td colspan="12">
			<table class="bordered-table" width="100%" cellpadding="5">
				<thead>
					<tr>
						<th colspan="2" class="header">{l s='Pre-filled Guest Section' pdf='true'}</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td width="25%" class="white bold">{l s='Full Name' pdf='true'}</td>
						<td width="75%" class="white">{$registration_form.guest.full_name|escape:'html':'UTF-8'}</td>
					</tr>
					<tr>
						<td class="white bold">{l s='Email' pdf='true'}</td>
						<td class="white">{$registration_form.guest.email|escape:'html':'UTF-8'}</td>
					</tr>
					<tr>
						<td class="white bold">{l s='Mobile Number' pdf='true'}</td>
						<td class="white">{$registration_form.guest.mobile|escape:'html':'UTF-8'}</td>
					</tr>
					<tr>
						<td class="white bold">{l s='Full Address' pdf='true'}</td>
						<td class="white">{$registration_form.guest.address}</td>
					</tr>
				</tbody>
			</table>
		</td>
	</tr>

	<tr>
		<td colspan="12" height="20">&nbsp;</td>
	</tr>

	<tr>
		<td colspan="12">
			<table class="bordered-table" width="100%" cellpadding="5">
				<thead>
					<tr>
						<th colspan="2" class="header">{l s='Hotel And Stay Details' pdf='true'}</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td width="25%" class="white bold">{l s='Hotel Name' pdf='true'}</td>
						<td width="75%" class="white">{$registration_form.hotel.name|escape:'html':'UTF-8'}</td>
					</tr>
					<tr>
						<td class="white bold">{l s='Hotel Address' pdf='true'}</td>
						<td class="white">{$registration_form.hotel.address}</td>
					</tr>
					<tr>
						<td class="white bold">{l s='Hotel Contact' pdf='true'}</td>
						<td class="white">
							{l s='Email:' pdf='true'} {$registration_form.hotel.email|escape:'html':'UTF-8'}<br />
							{l s='Phone:' pdf='true'} {$registration_form.hotel.phone|escape:'html':'UTF-8'}
						</td>
					</tr>
					<tr>
						<td class="white bold">{l s='Check-in / Check-out Time' pdf='true'}</td>
						<td class="white">
							{l s='Check-in:' pdf='true'} {$registration_form.hotel.check_in_time|escape:'html':'UTF-8'}<br />
							{l s='Check-out:' pdf='true'} {$registration_form.hotel.check_out_time|escape:'html':'UTF-8'}
						</td>
					</tr>
					<tr>
						<td class="white bold">{l s='Room Type' pdf='true'}</td>
						<td class="white">{$registration_form.stay.room_type|escape:'html':'UTF-8'}</td>
					</tr>
				</tbody>
			</table>
		</td>
	</tr>

	<tr>
		<td colspan="12" height="20">&nbsp;</td>
	</tr>

	<tr>
		<td colspan="12">
			<table class="bordered-table" width="100%" cellpadding="5">
				<thead>
					<tr>
						<th colspan="2" class="header">{l s='Manual Entry Section' pdf='true'}</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td width="50%" class="white">{l s='Date of Birth:' pdf='true'} ________________________</td>
						<td width="50%" class="white">{l s='Nationality:' pdf='true'} ________________________</td>
					</tr>
					<tr>
						<td class="white">{l s='ID/Passport Number:' pdf='true'} ________________________</td>
						<td class="white">{l s='Place of Issue:' pdf='true'} ________________________</td>
					</tr>
					<tr>
						<td class="white">{l s='Expiry Date:' pdf='true'} ________________________</td>
						<td class="white">{l s='Next Destination:' pdf='true'} ________________________</td>
					</tr>
					<tr>
						<td class="white">{l s='Purpose of Visit:' pdf='true'} [ ] {l s='Business' pdf='true'}   [ ] {l s='Leisure' pdf='true'}</td>
						<td class="white">{l s='Vehicle License Plate Number:' pdf='true'} ________________________</td>
					</tr>
				</tbody>
			</table>
		</td>
	</tr>

	<tr>
		<td colspan="12" height="20">&nbsp;</td>
	</tr>

	<tr>
		<td colspan="12">
			<table class="bordered-table" width="100%" cellpadding="5">
				<thead>
					<tr>
						<th class="header">{l s='Terms & Conditions' pdf='true'}</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td class="white">
							{l s='The guest agrees to comply with the hotel policies, house rules, and check-in/check-out timings. The guest is responsible for any damages, missing items, or policy violations during the stay, and authorizes the hotel to recover applicable charges in accordance with the booking terms.' pdf='true'}
						</td>
					</tr>
					<tr>
						<td class="white">
							{l s='Guest Signature:' pdf='true'} ___________________ &nbsp;&nbsp;&nbsp;&nbsp;
							{l s='Date:' pdf='true'} ___________
						</td>
					</tr>
				</tbody>
			</table>
		</td>
	</tr>

	{if isset($HOOK_DISPLAY_PDF)}
	<tr>
		<td colspan="12" height="20">&nbsp;</td>
	</tr>
	<tr>
		<td colspan="12">
			{$HOOK_DISPLAY_PDF}
		</td>
	</tr>
	{/if}
</table>
