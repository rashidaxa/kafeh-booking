-- ============================================================
-- Kafeh Booking — v25 migration
--   Editable Terms & Conditions. The widget's "Master Terms &
--   Conditions" link (booking step 3) opens a popup showing this HTML,
--   which an admin edits under Admin > Terms & Conditions. One set of
--   terms for every company; editing overwrites the text (no history).
--
--   Seeded with the terms from https://www.a1limousines.com/terms-and-conditions.html
--   (effective October 1, 2026) so the popup works straight away.
--
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v25.sql
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + INSERT IGNORE — re-running
-- never overwrites terms an admin has since edited. Safe to re-run.
-- ============================================================

USE `ground54_bookings_api`;

CREATE TABLE IF NOT EXISTS `kfb_terms` (
  `id`         TINYINT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'Always 1 — a single set of terms for every company',
  `html`       MEDIUMTEXT   NOT NULL COMMENT 'Sanitized HTML shown in the widget''s Terms & Conditions popup',
  `updated_at` DATETIME     NULL,
  `updated_by` VARCHAR(64)  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `kfb_terms` (`id`, `html`, `updated_at`, `updated_by`) VALUES
  (1, '<h2>A1 Limousines Terms and Conditions</h2>
<p><strong>Effective Date: October 1, 2026</strong></p>
<p>Please read these Website Terms &amp; Conditions carefully. By accessing the A1 Limousines website, requesting a quote, making a reservation, providing payment information, accepting a quotation, purchasing transportation services, or using our services, you acknowledge that you have read, understood, and agree to these Terms.</p>
<p>These Terms govern your use of the A1 Limousines website, reservation services, quotations, bookings, transportation services, and related services provided by A1 Limousine Service ("A1," "we," "us," or "our").</p>
<p>If you do not agree to these Terms, you should not access the website, make a reservation, purchase services, or use our transportation services.</p>
<h3>1. Reservations, Payment and Automatic Billing</h3>
<p>A reservation is not confirmed until A1 receives the required payment information and any required initial payment or retainer.</p>
<p>Unless otherwise stated in a written quotation or reservation-specific agreement, a 60% Initial Capacity Retainer is due immediately upon booking. The Initial Capacity Retainer is not a security deposit. It is an immediately earned charge for reserving vehicle and service capacity for the customer.</p>
<p>The remaining 40% balance is automatically charged to the credit card or other payment method on file when the reservation reaches its applicable Point of No Return.</p>
<p>For buses, including charter buses, motorcoaches, party buses, shuttle buses, and trolleys, the Point of No Return is 45 days before the scheduled service date. For sedans, SUVs, limousines, and vans, the Point of No Return is 30 days before the scheduled service date.</p>
<p>Once the applicable Point of No Return is reached, the remaining 40% balance becomes due and is automatically processed using the payment method on file.</p>
<p>Reservations made inside the applicable Point of No Return require 100% payment immediately and are non-refundable.</p>
<p>Any declined, reversed, disputed, or otherwise unpaid payment remains the customer''s responsibility.</p>
<p>Additional charges may apply for tolls, parking, extended waiting time, authorized additional services, unscheduled stops, route deviations, cleaning, damage, or other permitted charges.</p>
<h3>2. Cancellation Policy</h3>
<p>Cancellation charges depend on the vehicle category and when the cancellation is received.</p>
<h3>Category A: Buses</h3>
<p>Category A includes charter buses, motorcoaches, party buses, shuttle buses, and trolleys.</p>
<p>Cancellations received 46 or more days before the scheduled service date result in forfeiture of the 60% Initial Capacity Retainer, while the remaining 40% balance is waived.</p>
<p>Cancellations received 45 days or fewer before the scheduled service date result in automatic processing of the remaining 40% balance, making the entire 100% reservation amount non-refundable and forfeited.</p>
<h3>Category B: Sedans, SUVs, Limousines and Vans</h3>
<p>Cancellations received 31 or more days before the scheduled service date result in forfeiture of the 60% Initial Capacity Retainer, while the remaining 40% balance is waived.</p>
<p>Cancellations received 30 days or fewer before the scheduled service date result in automatic processing of the remaining 40% balance, making the entire 100% reservation amount non-refundable and forfeited.</p>
<p>Reservations made inside the applicable Point of No Return are subject to the 100% upfront, non-refundable policy described above.</p>
<h3>3. No-Show Policy</h3>
<p>For non-transit-hub pickups, a strict 15-minute grace period applies after the scheduled pickup time. After the grace period expires, A1 may release the vehicle and classify the reservation as a No-Show.</p>
<p>For airport and transit-hub pickups, A1 allows up to 30 minutes from the actual landing time for domestic flights and up to 60 minutes from the actual landing time for international flights.</p>
<p>If you cannot locate your vehicle, you must contact A1 Dispatch at 1-800-781-4700 before leaving the designated pickup area. Leaving the pickup area without contacting A1 may result in the reservation being classified as a No-Show.</p>
<p>A verified No-Show is subject to 100% of the applicable trip cost, plus an automatic 20% chauffeur gratuity and applicable base surcharges.</p>
<h3>4. Hourly Transportation</h3>
<p>Hourly transportation is billed according to the applicable hourly rate and minimum. Hourly service may include multiple stops, waiting time, event transportation, or other continuous transportation requirements.</p>
<p>A five-minute grace period applies after the scheduled end time. After the grace period, overtime is billed in one-hour increments at the applicable hourly rate, together with applicable operational fuel surcharges and a 20% chauffeur gratuity.</p>
<p>Passengers must remain safely seated whenever the vehicle is moving.</p>
<p>Unless expressly authorized by A1 and legally permitted, eating, drinking non-alcoholic beverages, smoking, vaping, using e-cigarettes, and cannabis use or smoking are prohibited inside the vehicle.</p>
<p>Passengers may not place any part of their body outside windows, doors, emergency exits, sunroofs, or moonroofs.</p>
<p>A chauffeur may terminate service immediately if passengers engage in illegal activity, possess weapons or controlled substances, make threats, engage in abusive behavior, or create an unsafe condition. No refund will be provided when service is terminated for prohibited conduct.</p>
<h3>5. Point-to-Point Transportation</h3>
<p>Point-to-point transportation is intended for travel from one pickup location to one destination without substantial waiting or unscheduled stops.</p>
<p>Customers may not require the vehicle to wait at a pickup or drop-off location for more than 10 minutes or make unscheduled stops unless authorized by A1.</p>
<p>Authorized additional waiting or unscheduled route deviations may be charged at $5.00 to $15.00 per minute, depending on the vehicle and service requirements, plus a 20% chauffeur gratuity.</p>
<h3>6. Cleaning, Damage and Remediation</h3>
<p>The customer is financially responsible for damage or extraordinary cleaning caused by the customer, passengers, guests, or other persons associated with the reservation.</p>
<p>To the extent permitted by law, A1 may charge the payment method on file for reasonable cleaning, remediation, repair, replacement, or restoration costs.</p>
<p><strong>Bodily Fluids and Biohazard Remediation:</strong> Bodily fluids, including vomit and other biohazard contamination, may require professional remediation. A1 may assess a remediation charge of up to $2,000 depending on the nature and extent of the contamination and the required remediation.</p>
<p><strong>Structural and Upholstery Damage:</strong> Structural damage or substantial damage to upholstery or vehicle components may result in charges of up to $5,000 depending on the nature and extent of the damage.</p>
<p>A1 may conduct a pre-service inspection and document pre-existing defects or conditions. Customers are not responsible for documented pre-existing damage.</p>
<h3>7. Smoking, Vaping and Cannabis</h3>
<p>Smoking of any kind, including cigarettes, cigars, electronic cigarettes, vaporizers, and cannabis, is prohibited in A1 vehicles unless expressly authorized by A1.</p>
<p>Customers are responsible for reasonable cleaning, deodorization, remediation, repair, and related costs resulting from prohibited smoking, vaping, cannabis use, contamination, or damage.</p>
<h3>8. Alcohol</h3>
<p>Alcohol consumption is subject to applicable law, vehicle type, and A1''s authorization.</p>
<p>A1 may prohibit or terminate alcohol consumption when it creates a safety concern, violates applicable law, results in disorderly or unsafe conduct, or violates vehicle or service restrictions.</p>
<p>Customers remain responsible for the conduct of all passengers traveling under their reservation.</p>
<h3>9. Illegal, Dangerous or Unsafe Conduct</h3>
<p>Weapons, illegal controlled substances, unlawful activity, threats, violence, abuse, harassment, and unsafe conduct are prohibited.</p>
<p>A1 may terminate service, remove passengers, contact law enforcement, and forfeit the applicable fare when prohibited conduct occurs.</p>
<p>No refund will be provided when service is terminated because of illegal, dangerous, abusive, threatening, or unsafe conduct.</p>
<h3>10. Sexual Activity</h3>
<p>Sexual activity or sexual conduct inside an A1 vehicle is strictly prohibited.</p>
<p>A1 may immediately terminate the trip and remove the passengers involved.</p>
<p>The entire applicable fare may be forfeited, and a mandatory $2,000 cleaning and sanitization fee will be assessed.</p>
<p>The vehicle may be removed from service for inspection, cleaning, and sanitization.</p>
<p>A vehicle partition may remain open when required for safety or operational reasons. Certain vehicle controls or features may also be disconnected when reasonably necessary.</p>
<h3>11. Vehicle Substitution</h3>
<p>A1 reserves the right to substitute a vehicle when reasonably necessary because of mechanical conditions, scheduling, availability, road conditions, safety concerns, operational requirements, or other circumstances.</p>
<p>Vehicle makes, models, colors, amenities, and specific configurations are not guaranteed unless expressly stated in writing.</p>
<p>A1 will make reasonable efforts to provide a substitute vehicle with comparable passenger capacity and service capability.</p>
<h3>12. Passenger Capacity</h3>
<p>Passengers may not exceed the legally permitted or manufacturer-rated capacity of the vehicle.</p>
<p>A1 may refuse transportation when the number of passengers, baggage, equipment, or other circumstances exceed the vehicle''s safe or legal capacity.</p>
<p>No refund is guaranteed when a customer arrives with more passengers or baggage than the reserved vehicle can legally or safely accommodate.</p>
<h3>13. Objectionable or Unsafe Passengers</h3>
<p>A1 may refuse service to or remove any passenger whose conduct creates a safety concern, violates applicable law, damages property, threatens or abuses a chauffeur or passenger, or materially interferes with safe vehicle operation.</p>
<p>The customer remains responsible for the conduct of all passengers traveling under the reservation.</p>
<h3>14. Baggage and Personal Property</h3>
<p>Passengers are responsible for their baggage and personal property.</p>
<p>A1 is not responsible for loss of or damage to personal property except to the extent required by applicable law.</p>
<p>Passengers should not leave valuables, identification documents, electronic devices, cash, jewelry, medication, or other important property inside a vehicle.</p>
<h3>15. Lost and Found</h3>
<p>Items discovered after a trip may be retained by A1 for a reasonable period while efforts are made to identify and return them to their owner.</p>
<p>Customers are responsible for providing accurate contact and delivery information.</p>
<p>Applicable shipping, delivery, handling, or transportation costs may be charged to the customer.</p>
<p>A1 is not responsible for items left behind except to the extent required by applicable law.</p>
<h3>16. Reservation Confirmations</h3>
<p>Customers are responsible for reviewing their reservation confirmation and promptly notifying A1 of any incorrect information.</p>
<p>Customers should carefully verify the date, pickup time, pickup location, destination, passenger count, vehicle type, flight information, stops, special instructions, contact information, and all other reservation details.</p>
<p>A reservation confirmation reflects the information available to A1 when the confirmation is issued.</p>
<h3>17. Reservation Changes and Email Communications</h3>
<p>Reservation changes are subject to availability and may result in additional charges.</p>
<p>Changes to pickup times, locations, destinations, vehicle types, passenger counts, stops, service duration, or other material reservation details may affect the applicable price and service requirements.</p>
<p>Customers are responsible for providing a valid email address and monitoring communications concerning their reservation.</p>
<h3>18. Day-of-Service Communication</h3>
<p>Customers must provide a valid mobile telephone number for the customer or designated contact and, when requested, an emergency contact.</p>
<p>The designated device should remain powered, charged, and capable of receiving telephone calls and text messages during the applicable service period.</p>
<p>A1 may make reasonable attempts to contact the customer or designated contact regarding pickup, delays, changes, or other service matters.</p>
<p>If the customer cannot be reached after reasonable attempts, A1 may rely upon the original reservation information.</p>
<p>A1 is not responsible for missed connections, missed transportation, rideshare expenses, delays, or other consequences resulting from a customer''s failure to remain reachable.</p>
<h3>19. Additional Charges</h3>
<p>Additional charges may apply when services exceed the scope of the original reservation or when additional costs are incurred in providing the service.</p>
<p>Additional charges may include, where applicable, additional service time, authorized waiting, unscheduled stops, route deviations, parking, tolls, cleaning, damage, special operational requirements, or additional services requested by the customer.</p>
<p>Applicable additional charges may be billed to the payment method on file.</p>
<h3>20. Fuel and Operational Surcharges</h3>
<p>A1 may apply applicable fuel or operational surcharges to transportation services.</p>
<p>When applicable, such surcharges may be identified in the quotation, reservation confirmation, invoice, or other service documentation.</p>
<h3>21. Sales and Use Taxes</h3>
<p>Applicable federal, state, local, municipal, airport, facility, and other governmental taxes and fees may be charged as required by law.</p>
<p>Customers are responsible for applicable taxes and governmental charges associated with their reservation.</p>
<h3>22. Child Safety Seats</h3>
<p>Customers are responsible for determining and complying with applicable child passenger safety requirements.</p>
<p>When a child safety seat is required, customers should notify A1 in advance.</p>
<p>Unless specifically confirmed in writing, customers should not assume that A1 will provide a particular child safety seat or restraint system.</p>
<h3>23. Service Animals and Pets</h3>
<p>Service animals will be accommodated as required by applicable law.</p>
<p>Other animals and pets are subject to A1''s vehicle, safety, and service policies and should be disclosed before the reservation.</p>
<p>Customers are responsible for reasonable cleaning, damage, or remediation costs caused by an animal, subject to applicable law.</p>
<h3>24. Affiliate Network and Subcontractors</h3>
<p>A1 may use qualified independent transportation providers, subcontractors, affiliates, or other transportation partners when necessary or appropriate to fulfill a reservation.</p>
<p>A1 may coordinate transportation through a worldwide affiliate and provider network. Independent providers may have their own operating procedures, vehicles, chauffeurs, and policies.</p>
<p>To the maximum extent permitted by applicable law, A1 is not responsible for independent operational acts or omissions of an independent subcontractor or affiliate beyond A1''s legally applicable responsibilities.</p>
<h3>25. Limitation of Liability</h3>
<p>To the maximum extent permitted by applicable law, A1 shall not be liable for special, indirect, incidental, punitive, exemplary, or consequential damages, lost profits, business interruption, or similar losses arising from or relating to transportation services, delays, cancellations, vehicle substitutions, traffic conditions, missed connections, or circumstances beyond A1''s reasonable control.</p>
<p>Nothing in these Terms is intended to waive or limit any right or protection that cannot lawfully be waived or limited.</p>
<h3>26. Force Majeure</h3>
<p>A1 is not responsible for delays, cancellations, interruptions, or inability to perform caused by circumstances beyond its reasonable control, including severe weather, Acts of God, traffic conditions, highway closures, road restrictions, mechanical failure, accidents, civic disruptions, government actions, emergency conditions, or other circumstances beyond A1''s reasonable control.</p>
<p>A1 will make reasonable efforts to provide the contracted service or an appropriate alternative when reasonably possible.</p>
<h3>27. Telephone and Dispatch Recordings</h3>
<p>Telephone calls, dispatch communications, and voice transmissions may be recorded for operational, quality assurance, safety, training, documentation, collection, dispute-resolution, and legal purposes, subject to applicable law.</p>
<p>By communicating with A1 through recorded telephone or dispatch systems, the customer acknowledges and consents to recording to the extent permitted by applicable law.</p>
<p>Recordings may be provided to applicable service providers, processors, collection agencies, attorneys, courts, or other authorized parties when reasonably necessary for legitimate business, collection, dispute-resolution, or legal purposes.</p>
<h3>28. Collection Costs, Attorneys'' Fees and Legal Expenses</h3>
<p>To the maximum extent permitted by applicable law, the customer is responsible for 100% of reasonable and documented costs incurred by A1 to collect amounts lawfully owed under a reservation or applicable agreement.</p>
<p>Such costs may include collection agency fees, attorneys'' fees, court costs, filing fees, administrative recovery costs, legal recovery costs, and other reasonable costs associated with collection or enforcement, to the extent permitted by law.</p>
<p>These obligations apply whether collection is pursued directly by A1, through a collection agency, through legal counsel, or through a court or other lawful proceeding.</p>
<h3>29. Governing Law and Exclusive Venue</h3>
<p>These Terms and applicable transportation services are governed by the laws of the State of Illinois, without regard to conflict-of-law principles.</p>
<p>To the extent permitted by applicable law, the exclusive legal venue for disputes arising from or relating to these Terms or A1 transportation services shall be the Circuit Court of the 22nd Judicial Circuit, McHenry County, Illinois.</p>
<p>Nothing in these Terms is intended to waive any non-waivable consumer rights provided by applicable law, including rights under the Illinois Consumer Fraud and Deceptive Business Practices Act.</p>
<h3>30. Severability</h3>
<p>If any provision of these Terms is determined to be invalid, unlawful, or unenforceable, that provision shall be severed or limited to the minimum extent necessary.</p>
<p>The remaining provisions shall remain in full force and effect to the maximum extent permitted by law.</p>
<h3>31. Copyright and Intellectual Property</h3>
<p>The content of the A1 Limousines website, including text, graphics, logos, photographs, designs, software, trademarks, service marks, and other materials, is owned by or licensed to A1 or their respective owners and is protected by applicable intellectual property laws.</p>
<p>No website content may be reproduced, copied, modified, distributed, transmitted, displayed, sold, licensed, or commercially exploited without prior written authorization.</p>
<h3>32. Website Use</h3>
<p>The website may be used only for lawful purposes.</p>
<p>Users may not attempt unauthorized access, interfere with website operations, introduce malicious code, scrape or harvest information for unauthorized purposes, misrepresent their identity, use the website for unlawful activity, or circumvent security or access controls.</p>
<p>A1 reserves the right to restrict or terminate access to the website when reasonably necessary to protect the website, its customers, or its business operations.</p>
<h3>33. General Terms</h3>
<p>These Terms constitute the general website terms applicable to A1''s online services and transportation reservations.</p>
<p>A1 may update these Terms from time to time. Updated Terms become effective when posted on the website unless a different effective date is stated.</p>
<p>The Terms applicable to a particular reservation are those presented or incorporated into the reservation, quotation, or applicable agreement at the time of booking, subject to the document-precedence provisions below.</p>
<p>A1''s failure to enforce any provision does not constitute a waiver of that provision.</p>
<h3>34. Relationship to the Master Transportation Charter &amp; Rental Agreement</h3>
<p>These Website Terms &amp; Conditions are intended to operate together with A1''s Master Transportation Charter &amp; Rental Agreement.</p>
<p>These Website Terms apply generally to website users, customers, reservations, and transportation services.</p>
<p>When a customer enters into a separate Master Transportation Charter &amp; Rental Agreement covering a particular reservation or transportation service, the Master Agreement governs the specific reservation and services covered by that agreement.</p>
<p>These Website Terms supplement the Master Agreement except where the documents expressly conflict.</p>
<h3>If a provision of the signed Master Transportation Charter &amp; Rental Agreement conflicts with these Website Terms with respect to a reservation covered by that Master Agreement, the signed Master Transportation Charter &amp; Rental Agreement will control for that reservation.</h3>
<p>Nothing in these Website Terms is intended to alter, replace, or eliminate the payment, cancellation, service, collection, or other obligations expressly established in an applicable Master Transportation Charter &amp; Rental Agreement.</p>
<h3>35. No Waiver of Non-Waivable Rights</h3>
<p>Nothing in these Terms is intended to waive, release, or limit any right, remedy, protection, or requirement that cannot lawfully be waived, released, or limited under applicable federal, state, or local law.</p>
<p>Where a provision may legally be limited but not eliminated, it shall be interpreted and enforced to the maximum extent permitted by applicable law.</p>
<h3>36. Disclaimer</h3>
<p>Transportation services are subject to availability, operating conditions, traffic, weather, road conditions, vehicle availability, governmental restrictions, and other circumstances that may affect service.</p>
<p>A1 does not guarantee uninterrupted transportation or arrival at a specific time when circumstances beyond A1''s reasonable control prevent timely performance.</p>
<p>Customers are responsible for allowing sufficient travel time for traffic, airport procedures, weather, security, events, construction, and other foreseeable or unforeseeable delays.</p>
<h3>Electronic and Telephone Acceptance</h3>
<p>A customer may accept these Terms electronically or, for telephone reservations, by providing affirmative verbal acceptance to an A1 Limousines representative.</p>
<p>Where permitted by applicable law, telephone conversations may be recorded and transcribed. Electronic records, customer acceptance records, recordings, and transcriptions may be retained as evidence of the customer''s authorization and acceptance.</p>
<p>The method of acceptance and its legal effect are determined by applicable law and the circumstances of the transaction.</p>
<h3>Acceptance of These Terms</h3>
<p>No handwritten or electronic signature is required for these Website Terms to apply.</p>
<p>By accessing the A1 Limousines website, requesting a quote, making a reservation, providing payment information, accepting a quotation, purchasing transportation services, or using A1 transportation services, the customer acknowledges that they have had an opportunity to review these Terms and agrees to be bound by them to the maximum extent permitted by applicable law.</p>
<p>Where A1 presents these Terms during an online reservation or checkout process, the customer''s affirmative acceptance, electronic acknowledgment, reservation, payment, or use of services constitutes acceptance of the applicable Terms.</p>
<p><strong>A1 Limousine Service</strong><br><strong>Website:</strong> <a href="https://www.a1limousines.com/" target="_blank" rel="noopener noreferrer">A1Limousines.com</a><br><strong>Reservation &amp; Dispatch:</strong> 1-800-781-4700</p>', NOW(), 'initial import');
