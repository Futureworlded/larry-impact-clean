# Larry Impact Site — Quick Client Walkthrough

How to manage the site day-to-day without touching code.

## 1. Log in
- Go to: `https://larryimpact.com/wp-admin`
- Use the WordPress username and password Michael provides.

## 2. Edit pages with Beaver Builder
- In the left menu: **Pages** > choose the page (Home, About Us, Contact Us, etc.)
- Click **Launch Beaver Builder** (or **Beaver Builder** at the top of the page).
- Hover over any block and click the wrench icon to edit text, images, headings, or buttons.
- Click **Done** > **Publish** when finished.

## 3. Edit the global header / footer
- Left menu: **Beaver Builder** > **Themer Layouts**
- Open **Header** or **Footer**.
- Header logo is a Photo module; nav links are Rich Text modules.
- Footer logo, tagline, social icons, and footer links are also modules you can edit.
- Click **Done** > **Publish**.

## 4. Add or change Merch / Impactlist products
- These come from **Printful**.
- Log into Printful and make sure you are in the **Larry Rescue** store.
- Add or edit products there and push them to WooCommerce.
- Important: products added to the other Printful store (**Let A Rescue Rescue You**) will NOT show on `larryimpact.com`.
- Back in WordPress, check the products under **WooCommerce** > **Products** and set the correct category (`Merch` or `Impactlist`).

## 5. Coffee products
- Coffee is currently sold through a separate Shopify store.
- To update coffee listings on the homepage, edit the homepage in Beaver Builder and update each coffee card (Photo + Rich Text module).
- Major coffee catalog changes are done in Shopify.

## 6. Orders and customers
- **WooCommerce** > **Orders** shows all Merch / Impactlist orders.
- **WooCommerce** > **Customers** shows buyer accounts.

## 7. View and update the Operations Manual
- Left menu: **Larry Impact** > **Operations Manual**
- The manual displays at the top.
- To paste your own HTML, expand **Edit HTML** at the bottom, paste, and save.
- Use **Reset to Markdown** to restore the original text if needed.

## 8. Before full public launch
The following security items still need to be done once (ask Michael or your developer):
- Rotate WordPress salts in `wp-config.php`.
- Change the MySQL database password and update `wp-config.php`.
- Roll the Stripe keys and update them in `wp-config.php` and the Larry Impact settings.
- Regenerate Supabase keys and update `wp-config.php`.

## 9. Recent updates and where to find payments

### Payments
- **Orders:** **WooCommerce** > **Orders** lists all Merch / Impactlist orders.
- **Financial summary:** **Larry Impact** > **Reports** shows revenue, payouts, refunds, and liabilities.
- **Money owed to rescues:** **Larry Impact** > **Payouts** shows pending batches and lets you pay them.
- **Stripe / bank status:** **Larry Impact** > **Rescues** > click a rescue > **Account**. This shows whether the rescue has connected a Stripe account. If not, click **Send onboarding email** or tell the rescue to log in at `/rescue-login/` and choose **Setup / My account > Connect bank account**.

### Merch design categories
Merch is now organized by design. Each design is a subcategory under **Merch**:
- People Suck
- Black Dog Lives Matter
- Dog Free Is Not Freedom
- Some Hearts Have Fur
- Peace Love And Rescue
- People Are Why Dogs Need Rescuing

When you add a product, assign it to the matching design subcategory so it appears on the homepage and rescue pages.

### Coffee priority
The homepage and rescue pages list coffee in this order: **Pony Espresso**, **Rescue Roast**, **Bark Roast**, then the other coffees. Update coffee cards in Beaver Builder if you need to change text or images.

### YOUR IMPACT on rescue pages
Each rescue detail page shows a **YOUR IMPACT** card that explains how shopping supports that rescue.

### Clean rescue page URLs
Rescue page slugs can be edited from **Larry Impact** > **Rescues** > click a rescue > **Profile**. Use the **URL slug** field, click **Update rescue slug**, then run **Larry Impact** > **Rescue Pages** > **Sync All Rescue Pages Now**.

### Homepage default rescue
If a shopper buys from the homepage without first choosing a rescue, the sale is attributed to the default rescue (currently **Let A Rescue Rescue You**).

## 10. Need help?
- Beaver Builder guide: `https://docs.wpbeaverbuilder.com/`
- WooCommerce guide: `https://woocommerce.com/documentation/`
