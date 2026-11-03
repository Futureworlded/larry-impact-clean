# Larry Impact Operations Manual

This guide explains how each part of the Larry Impact application works and what to do in each section. It is written for the management team, so it avoids technical language and focuses on day-to-day use.

---

## Table of Contents

1.  [How the overall system works](#how-the-overall-system-works)
2.  [Public-facing areas](#public-facing-areas)
    -   Apply to become a rescue partner
    -   Rescue login
    -   Rescue dashboard
    -   Public rescue pages
3.  [Admin areas](#admin-areas)
    -   Rescues
    -   Applications
    -   Split Dashboard
    -   Split Configurator
    -   Payouts
    -   Reports
    -   Settings
    -   Rescue Pages
4.  [Common workflows](#common-workflows)
5.  [Important notes](#important-notes)

---

## How the overall system works

Larry Impact is a platform where supporters buy products and a fixed dollar amount from every sale goes to an animal rescue organization. Here is the lifecycle of a typical order:

1.  A shopper visits the store and buys a product.
2.  The system records the order, locks the sale, and calculates the rescue share.
3.  The rescue sees the order in its dashboard and earnings page.
4.  When enough money is owed to a rescue, the admin can prepare a payout batch.
5.  The batch is reviewed, approved, and paid to the rescue's connected account.
6.  The rescue receives an email that the payout is on its way.
7.  If a shopper visits the homepage without first choosing a rescue, the sale is attributed to the default rescue (currently **Let A Rescue Rescue You**).

The system also watches for refunds and disputes. If a customer is refunded after a payout has already been sent, the rescue's next payout is adjusted automatically.

The rescue payout is a fixed dollar amount per product, set in the Split Configurator. Larry Impact receives the remainder of the sale after the rescue amount and product cost.

---

## Public-facing areas

### Apply to become a rescue partner

This is the page where a rescue organization applies to join the platform.

**What the rescue does:**
1.  Go to `/apply/`.
2.  Fill out the application form.
3.  Use a real organization email address, or a fake test address like `someone@example.com` when testing.
4.  Submit the form.

**What happens next:**
- The application appears in **Larry Impact > Applications** as "Pending."
- The admin receives an email notification if application alerts are turned on.
- When the application is approved, the rescue receives a username and password by email and can log in.

### Rescue login

This is the page where approved rescues sign in to their account.

**What the rescue does:**
1.  Go to `/rescue-login/`.
2.  Enter the email address used on the application.
3.  Enter the password sent in the approval email.
4.  Click **Sign In**.

**What happens next:**
- The rescue is taken to its dashboard.
- Only users with rescue partner access can view the dashboard. Admins can view it as well.

### Rescue dashboard

After logging in, rescues land on `/dashboard/`. The dashboard has several tabs:

#### Overview
- Shows a welcome message and summary of the rescue's account.
- If the rescue page is not yet built, it may say "Your profile is being set up. Check back shortly."

#### My profile
- The rescue can update its public profile picture, description, location, contact information, social media links, and the rescue representative.
- This information appears on the public rescue page.

#### My earnings
- Shows total sales, rescue earnings, and any pending payout balance.
- Rescues can see which orders have been recorded and whether they are pending, ready for payout, approved, or paid.

#### Setup / My account
- Used to connect a Stripe account so the rescue can receive direct deposits.
- If the rescue has not completed this step, payouts cannot be sent.
- The rescue clicks **Connect bank account**, completes Stripe's onboarding, and the admin can see the connection status under **Larry Impact > Rescues > [rescue] > Account**.
- If a rescue loses the onboarding email or gets stuck, the admin can open the rescue's **Account** tab and click **Send onboarding email** to resend the link.

#### Settings
- Lets the rescue update its display name, email, and password.

### Public rescue pages

Each approved rescue gets its own public page on the website. These pages can be found under `/rescues/`.

**What the rescue page shows:**
- The rescue's name, logo, and description.
- Any products linked to the rescue.
- Links to the rescue's website or social media.

**How to create or update them:**
- In the admin, go to **Larry Impact > Rescue Pages**.
- Click **Sync All Rescue Pages Now**.
- The system generates one page for each approved rescue.

**What appears on a rescue page:**
- The rescue name, logo, description, and social links.
- A **YOUR IMPACT** card that explains how shopping supports that rescue.
- Merch products grouped by design subcategory under the main **Merch** category.
- Coffee products in the priority order: **Pony Espresso**, **Rescue Roast**, **Bark Roast**, followed by the rest.

---

## Admin areas

All admin functions are under the **Larry Impact** menu in the WordPress admin panel.

### Rescues

This is the main list of all rescue organizations.

**What you see:**
- Rescue name
- Location
- Contact email
- Status: pending, approved, or declined
- Date joined

**What you do:**
- Review the list.
- Pending rescues appear here only after they are approved on the Applications page.
- If a rescue's profile is missing, click the rescue row or use **Rescue Pages** to sync its public page.

#### Account / bank status
- In the rescue list, click a rescue name to open its profile.
- Click the **Account** tab.
- If the rescue has connected a Stripe account, the status and last-4 digits are shown.
- If the rescue has not connected a bank account, click **Send onboarding email** to resend the Stripe Connect link.
- The rescue can also log in to `/rescue-login/`, go to **Setup / My account**, and click **Connect bank account** to start the process.

### Applications

This is where new rescue applications are reviewed.

**How to use it:**
1.  Go to **Larry Impact > Applications**.
2.  The default view shows **Pending** applications.
3.  Review the organization name, contact name, phone, email, and location.
4.  To approve, click **Approve**. The system will:
    - Create a WordPress user for the rescue.
    - Assign the rescue partner role.
    - Send the rescue an email with login credentials.
    - Add the rescue to the **Rescues** list.
5.  To decline, click **Decline**. The rescue will not be added.
6.  Use the **Approved** and **Declined** tabs to review past decisions.

**Testing tip:**
- When testing the approval flow, use a fake identity such as `Test Rescue / someone@example.com`. Do not approve real applications unless you intend to onboard that rescue.

### Split Dashboard

This shows how revenue is divided between the rescue, Larry Impact, and product costs.

**What you see:**
- Products and their associated rescues.
- The fixed dollar rescue payout and Larry Impact remainder for each product.
- Estimated earnings per sale.

**What you do:**
- Use this page to quickly review which products are linked to which rescues.
- If a payout looks wrong, go to **Split Configurator** to update it.

### Split Configurator

This is where you control the fixed dollar amount that goes to the rescue for each product. Larry Impact keeps the remainder of the sale after the rescue amount and product cost.

**Important concept:**
- The product list is built from the live WooCommerce catalog, so it always reflects the products that actually exist.
- Products that no longer exist in WooCommerce are shown with a red **(not in WooCommerce)** label.
- Splits are stored as a history of versions. When you change an amount, the system records the new version with a date.
- Old orders keep the payout that was active when the order happened. New orders use the latest amount.
- This means you can safely change future payouts without changing past earnings.

**How to update a product payout:**
1.  Go to **Larry Impact > Split Configurator**.
2.  Find the product in the list.
3.  Enter the **Amount to rescue** (the fixed dollar amount the rescue receives for each unit sold).
4.  The **Larry keeps** amount updates automatically.
5.  Click **Save changes**.

**How to recalculate all products:**
- If product prices or costs have changed, click **Recalculate all** to refresh the defaults.
- The system will use the default rescue share set in **Settings** unless a specific value is saved for a product.

**How to remove obsolete products:**
- If you see old products labeled **(not in WooCommerce)**, click **Hide** on that row to remove it from the list.
- To hide all obsolete products at once, click **Clean up obsolete products** at the top of the page.

### Payouts

This is where you manage money owed to rescues.

**Important concept:**
The payout workflow has four stages:
1.  **Pending** - The order has been recorded, but no payout batch has been created.
2.  **Ready for payout** - A batch has been prepared for the rescue, but no money has been sent.
3.  **Approved** - The admin has approved the batch and the transfer has been initiated.
4.  **Paid / Completed** - The money has reached the rescue.
5.  **Archived** - The batch is complete and stored for record-keeping.

**How to prepare a payout batch:**
1.  Go to **Larry Impact > Payouts**.
2.  At the top, click **Prepare payout batches**.
3.  The system groups all pending orders for each approved rescue into a batch.
4.  Each batch appears in the **Payout batches** table with status "Ready for payout."

**How to approve and pay a batch:**
1.  In the **Payout batches** table, find the batch you want to pay.
2.  Click **Approve & Pay**.
3.  The system sends the money to the rescue's connected Stripe account.
4.  The batch status changes to "Approved."

**How to mark a batch completed manually:**
- If the payment company confirms the transfer outside the system, click **Mark completed** on the batch.

**How to archive a batch:**
- After a batch is completed, click **Archive** to store it for records.

**How to roll back a batch:**
- If a batch was approved by mistake, click **Rollback**. This records a negative entry and stops the payout from being counted as paid.

**Approving all ready batches at once:**
- Click **Approve all ready** to send every batch that is currently ready.
- Only use this when you are confident all rescues have correct bank or payment details.

**Searching and filtering orders:**
- Below the batch table is the **Orders** section.
- Use the **Filter** dropdown to show all orders, pending orders, ready orders, approved orders, or paid orders.
- Use the **Search** box to find an order by order number, product name, or rescue name.
- Use **Previous** and **Next** to move through pages of orders.

### Reports

This page gives a high-level financial summary.

**What you see:**
- **Total Revenue** - All recorded sales.
- **Rescue Payouts** - Money sent or owed to rescues.
- **Net / Larry Revenue** - What is left for the platform.
- **Outstanding Liability** - Money owed to rescues but not yet paid.
- **Orders** - Number of tracked orders.
- **Average Order** - Average sale value.
- **Refunds** - Total refunded to customers.
- **Chargebacks** - Total disputed or lost payments.
- **Top Rescues** - Which rescues have earned the most.
- **Top Products** - Which products have generated the most revenue.
- **Sales by Month** - Revenue trend over time.
- **Recent Payout Batches** - Latest payout activity.
- **Fraud Flags** - Warnings about duplicate orders or other suspicious activity.

**Exporting the ledger:**
- Click **Export ledger CSV** to download a spreadsheet of every recorded transaction.
- This is useful for the bookkeeper or accountant.

### Settings

This is where platform-wide defaults are managed.

**What you can change:**
- **Platform name** - The name used in emails.
- **Admin notification email** - Where new application alerts are sent.
- **Default rescue share** - The default percentage of net profit used to calculate a starting rescue payout for new products. You can override this per product in the Split Configurator.
- **Minimum payout threshold** - The smallest amount a rescue must earn before a payout batch is created.
- **Currency code and symbol** - The currency used for reports and payouts, for example `USD` and `$`.
- **Stripe credentials** - The connection to the payment company. These should only be changed by the person managing the payment account.
- **Shopify store domain** - The online store address used for admin links.
- **Email settings** - The name and email address that application and payout emails come from.

**When to use it:**
- Update the minimum payout if you want payouts to happen more or less often.
- Change the default rescue share before adding new products.
- Update the admin email if the person handling applications changes.

### Rescue Pages

This page lets you generate or refresh the public profile pages for each approved rescue.

**How to use it:**
1.  Go to **Larry Impact > Rescue Pages**.
2.  Click **Sync All Rescue Pages Now**.
3.  The system creates one page per approved rescue.
4.  Visit `/rescues/` on the front of the site to see the list.

---

## Common workflows

### Approve a new rescue

1.  Go to **Applications**.
2.  Find the pending application.
3.  Click **Approve**.
4.  The rescue receives a login email.
5.  Go to **Rescue Pages** and click **Sync All Rescue Pages Now** to publish the rescue's public page.

### Change a product payout

1.  Go to **Split Configurator**.
2.  Find the product.
3.  Enter the **Amount to rescue** (fixed dollar amount per unit).
4.  The **Larry keeps** amount updates automatically.
5.  Click **Save changes**.
6.  The system records a new payout version. New orders will use the new amount. Old orders keep the old amount.

### Pay a rescue

1.  Go to **Payouts**.
2.  Click **Prepare payout batches**. This groups pending orders into ready batches.
3.  Review each batch in the **Payout batches** table.
4.  For each batch you want to pay, click **Approve & Pay**.
5.  The batch status changes to **Approved**, then later **Completed** when the transfer is confirmed.
6.  Click **Archive** when the batch is fully settled.

### Handle a refund

- If a customer is refunded before the payout, the order is marked as refunded and the rescue is not paid for that order.
- If a customer is refunded after the payout has already been sent, the system records a negative entry on the rescue's next payout.

### Handle a chargeback or dispute

- When a payment dispute comes in, the system marks the order as disputed.
- The platform should stop the related payout if possible and adjust future payouts until the dispute is resolved.

### Add a Merch product under the new design categories

1.  Add or edit the product in the **Larry Rescue** Printful store and push it to WooCommerce.
2.  In WordPress, go to **WooCommerce > Products**.
3.  Assign the product to the matching design subcategory under **Merch** (for example, **People Suck** or **Black Dog Lives Matter**).
4.  Go to **Larry Impact > Rescue Pages** and click **Sync All Rescue Pages Now** so the product appears on rescue pages.

### Check a rescue's Stripe / bank status or resend onboarding

1.  Go to **Larry Impact > Rescues**.
2.  Click the rescue name and open the **Account** tab.
3.  If a Stripe account has been created but onboarding is not complete, click **Send onboarding email** to resend the Stripe Connect link.
4.  If no Stripe account exists, the rescue must first log in at `/rescue-login/`, go to **Setup / My account**, and click **Connect bank account** to create one.
5.  If the rescue sees a Stripe error, the platform owner must enable Stripe Connect at https://dashboard.stripe.com/connect.

### Update a rescue page URL

1.  Go to **Larry Impact > Rescues**.
2.  Click the rescue and open the **Profile** tab.
3.  Edit the **URL slug** field and click **Update rescue slug**.
4.  Go to **Larry Impact > Rescue Pages** and click **Sync All Rescue Pages Now**.

### Clean up obsolete products in the Split Configurator

1.  Go to **Larry Impact > Split Configurator**.
2.  Obsolete products are labeled **(not in WooCommerce)**.
3.  Click **Hide** on a single row to remove it, or click **Clean up obsolete products** to hide every obsolete product at once.

### Where to find payments

- **Orders:** **WooCommerce > Orders** lists every Merch / Impactlist order.
- **Financial summary:** **Larry Impact > Reports** shows revenue, payouts, refunds, and liabilities.
- **Money owed to rescues:** **Larry Impact > Payouts** shows pending batches and lets you pay them.
- **Stripe / bank status:** **Larry Impact > Rescues > [rescue] > Account** shows whether the rescue has connected a bank account.

---

## Important notes

- **Do not approve or modify real rescue data unless you intend to.** Use fake information like `someone@example.com` when testing the application flow.
- **Always verify a rescue's payout details before approving a batch.** If a rescue has not connected a payment account, the transfer will fail.
- **Payouts are not automatic until the payment company connection is fully configured.** A manual approval step is required for each batch.
- **Keep the ledger export for accounting.** The CSV export in Reports is the official record of sales, payouts, refunds, and adjustments.
- **Contact the platform manager or developer if you need to change payment keys, currencies, or the minimum payout threshold.** These affect real money and should be handled carefully.


---

## Adding a new Shopify SKU and rescue payout

Use this process whenever a new coffee size, grind, roast, or other Shopify product is added.

### Simple version

1. Add the product and SKU in Shopify.
2. In WordPress, go to **Products > Add new product**.
3. Enter a clear product name.
4. Under **Product data > Inventory**, enter the exact same SKU used in Shopify.
5. Under **Product data > General**, enter the matching Shopify sale price.
6. Enter the fixed **Rescue payout ($)** for one unit.
7. Select the **Coffee** category.
8. Set **Catalog visibility** to **Hidden**. This keeps the internal mapping product out of the public WooCommerce shop.
9. Set **Stock status** to **Out of stock** so the mapping product cannot be purchased directly through WooCommerce.
10. Click **Publish**.
11. Go to **Larry Impact > Split Configurator**.
12. Find the exact SKU, enter the rescue amount again, and click **Save changes**.
13. Wait for the green confirmation message, then reload the page and confirm the amount is still correct.

### Advanced explanation

The SKU is the connection between the Shopify order and Larry Impact. It must match exactly, including every letter, number, and hyphen. When Shopify sends an order, Larry Impact reads the SKU, finds the matching internal product, identifies the rescue chosen by the customer, and applies the saved fixed-dollar rescue amount.

The hidden WooCommerce product is an internal mapping record. It must remain **Published** so it appears in Split Configurator, but its catalog visibility stays **Hidden** and its stock status stays **Out of stock** so customers do not see or buy it directly.

The amount entered in Split Configurator is a fixed amount per unit, not a percentage. For example, a rescue amount of **$6.00** on a quantity of two produces **$12.00** in rescue earnings.

Always finish by clicking **Save changes** and waiting for the green success message. Split Configurator saves products in small batches to prevent timeout errors. Unsaved entries are preserved in the browser until the save completes.
