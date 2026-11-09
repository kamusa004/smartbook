/* SmartBook interface strings - English (default), Luganda, Runyankole.
 * Adding a language = add one object. Nothing else in the app changes. */
const I18N = (() => {
  const DICT = {
    en: {
      dashboard: 'Dashboard', sell: 'Sell', products: 'Products', customers: 'Customers',
      reports: 'Reports', more: 'More', today: 'Today', this_week: 'This week', this_month: 'This month',
      sales_today: 'Sales today', revenue: 'Revenue', profit: 'Profit', expenses: 'Expenses',
      low_stock: 'Low stock', out_of_stock: 'Out of stock', outstanding_credit: 'Outstanding credit',
      add_product: 'Add product', search_products: 'Search products or scan barcode',
      cart: 'Cart', total: 'Total', complete_sale: 'Complete sale', payment: 'Payment',
      cash: 'Cash', credit: 'Credit', save: 'Save', cancel: 'Cancel', logout: 'Sign out',
      sync_ok: 'All synced', offline_note: 'You are offline. Sales are saved on this phone and will sync automatically.',
      stock: 'Stock', price: 'Price', quantity: 'Quantity', name: 'Name', phone: 'Phone',
      employees: 'Employees', suppliers: 'Suppliers', analytics: 'Smart analytics',
      settings: 'Settings', subscription: 'Subscription'
    },
    lg: {
      dashboard: 'Ekifaananyi', sell: 'Tunda', products: 'Ebintu', customers: 'Bakasitoma',
      reports: 'Alipoota', more: 'Ebisingawo', today: 'Leero', this_week: 'Wiiki eno', this_month: 'Omwezi guno',
      sales_today: 'Ebitundiddwa leero', revenue: 'Ssente eziyingidde', profit: 'Amagoba',
      expenses: 'Ebisale', low_stock: 'Ebikyali katono', out_of_stock: 'Ebikomye',
      outstanding_credit: 'Amabanja agasigadde', add_product: 'Yongera ekintu',
      search_products: 'Noonya ekintu', cart: 'Ekibokisi', total: 'Wamu',
      complete_sale: 'Kakasa okutunda', payment: 'Ensimbi', cash: 'Ssente mpeera',
      credit: 'Amabanja', save: 'Tereka', cancel: 'Sazaamu', logout: 'Fuluma',
      sync_ok: 'Byonna bitundiddwa', offline_note: 'Toli ku mutimbagano. Okutunda kuterekebwa ku ssimu yo.',
      stock: 'Ebisigadde', price: 'Omuwendo', quantity: 'Omuwendo gw\u2019ebintu', name: 'Erinnya', phone: 'Essimu',
      employees: 'Abakozi', suppliers: 'Bawaabi', analytics: 'Okwekenneenya',
      settings: 'Entegeka', subscription: 'Ekitundu ky\u2019okuwagira'
    },
    nyn: {
      dashboard: 'Ekirorero', sell: 'Gurisha', products: 'Ebintu', customers: 'Bakasitoma',
      reports: 'Alipoota', more: 'Ebindi', today: 'Erizooba', this_week: 'Esande egi', this_month: 'Omwezi ogu',
      sales_today: 'Ebigurishirwe erizooba', revenue: 'Sente ezihingwire', profit: 'Amagoba',
      expenses: 'Ebihwereire', low_stock: 'Ebikyari bike', out_of_stock: 'Ebihweraire',
      outstanding_credit: 'Amabanja agarahi', add_product: 'Taho ekintu',
      search_products: 'Noonya ekintu', cart: 'Ekibokisi', total: 'Hamwe',
      complete_sale: 'Kakasa okugurisha', payment: 'Sente', cash: 'Sente z\u2019omukono',
      credit: 'Amabanja', save: 'Bika', cancel: 'Kanzura', logout: 'Fumuuka',
      sync_ok: 'Byona bikahikire', offline_note: 'Tori aha mutimbagano. Ebikaguzirwe nibibikwa aha ssimu yawe.',
      stock: 'Ebihaire', price: 'Omuramo', quantity: 'Omuramo gw\u2019ebintu', name: 'Eizina', phone: 'Esimu',
      employees: 'Abakazi', suppliers: 'Abatwari', analytics: 'Okwekenneenya',
      settings: 'Entegeka', subscription: 'Ekitundu ky\u2019okuwagira'
    }
  };

  let lang = localStorage.getItem('sb_lang') || 'en';
  if (!DICT[lang]) lang = 'en';

  function t(key) {
    return (DICT[lang] && DICT[lang][key]) || DICT.en[key] || key;
  }
  function set(next) {
    if (DICT[next]) {
      lang = next;
      localStorage.setItem('sb_lang', lang);
      document.documentElement.lang = lang;
      document.dispatchEvent(new CustomEvent('sb:lang', { detail: lang }));
    }
  }
  function apply(root) {
    (root || document).querySelectorAll('[data-i18n]').forEach((el) => {
      el.textContent = t(el.dataset.i18n);
    });
  }
  return { t, set, apply, get lang() { return lang; }, languages: Object.keys(DICT) };
})();
