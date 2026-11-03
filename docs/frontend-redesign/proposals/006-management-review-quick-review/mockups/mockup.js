/*
 * Design-only mockup harness for the Cost Center Manager inline quick-review
 * proposal (#006). Renders a static, illustrative board with URL-driven states.
 * NOT production code. No backend calls. All data is invented for visual review.
 */

"use strict";

/* ---------------------------------------------------------------------------
 * Harness copy (toolbar / shell labels) — EN + FA
 * ------------------------------------------------------------------------- */

const COPY = {
	en: {
		dir: "ltr",
		toolTitle: "Cost center manager · inline quick review",
		toolBrand: "Design proposal #006",
		toolNote: "Design-only mockup · illustrative data · not an implementation",
		segState: "State",
		segDevice: "Viewport",
		segLang: "Language",
		states: [
			["collapsed", "Collapsed queue"],
			["expanded", "Quick review"],
			["doc", "Doc preview"],
			["unsupported", "Unsupported file"],
			["details", "Full details"],
			["confirm", "Confirm decision"],
			["return", "Confirm return"],
			["error", "Reject w/o note"],
			["submitting", "Submitting"],
			["completed", "Decision complete"],
		],
		devices: [
			["desktop", "Desktop"],
			["mobile", "Mobile"],
		],
		langs: [
			["en", "English"],
			["fa", "فارسی"],
		],
		crumbs: ["Petty cash", "Cost center review"],
		simPill: "DESIGN MOCKUP",
		nav: [
			["home", "Home", null],
			["sheets", "My sheets", null],
			["approvals", "Approvals", 3],
			["centers", "Cost centers", null],
			["reports", "Reports", null],
		],
		userRole: "Cost center manager",
		pageEyebrow: "Cost Center North", // literal latin name — LTR isolate handled in markup
		pageTitle: "Management queue",
		pageSubtitle:
			"Transactions routed to Cost Center North are reviewed here, inline, without leaving the queue.",
		roleBadge: "Cost center manager",
		bannerTitle: "You are deciding as Cost Center North",
		bannerText:
			"Each transaction below is decided on its own. This workspace never approves, returns or rejects a whole sheet at once.",
		bannerLink: "Review policy",
		statSheets: "Sheets awaiting decisions",
		statSheetsHint: "Newest submitted first",
		statTx: "Transactions pending decision",
		statTxHint: "1 sheet is partially decided",
		queueTitle: "Relevant transactions",
		queueDesc:
			"Manus-style focused order: only cost-center-relevant sheets, grouped as sheets, with the transactions you must decide on, expanded in place.",
		searchPlaceholder: "Search sheet, purchaser or ID…",
		searchLabel: "Search the queue",
		availabilityNote:
			"Illustrative data only. Sheet title, purchaser and totals are design-target fields (FR-006-002/FR-006-003) — they are not yet delivered by the API and are rendered here as intended design.",
		colSheet: "Sheet",
		colPurchaser: "Purchaser",
		colTx: "Txs",
		colTotal: "Total",
		colStatus: "Status",
		colSubmitted: "Submitted",
		sortLabel: "Sort",
		sortOptions: ["Newest submitted first", "Amount · high to low", "Amount · low to high"],
		tabs: [
			["All sheets", 3],
			["Awaiting decision", 2],
			["In progress", 1],
		],
		pillAwaiting: "Awaiting review",
		pillInProgress: "In progress",
		pillApproved: "Approved",
		pillRejected: "Rejected",
		pillReturned: "Returned",
		statTxLabel: "Transactions",
		statTotalLabel: "Total",
		chevronOpen: "Expand quick review for",
		chevronClose: "Collapse quick review for",
		bodyEyebrow: "Quick review",
		purchaserLabel: "Purchased by",
		scopeLabel: "In scope",
		collapseLink: "Collapse",
		footTitle: "Sheet-wide decisions are not possible.",
		footText:
			"This design keeps the decision gate transaction-by-transaction. A sheet leaves the queue only when every relevant transaction has a decision. Whole-sheet actions are intentionally unavailable.",
		openDetails: "Full details",
		openPreview: "Preview",
		approve: "Approve",
		reject: "Reject",
		returnTx: "Return",
		decidedNote: "Approved by you",
		decidedFor: "for",
		undo: "Undo",
		docsLabel: "Attachments",
		docsMore: "more",
		docsOffscreen: "Opens document preview",
		detailsOnly: "Preview not available for this type — open full details",
		confirmTitle: "Approve transaction?",
		confirmTitleOff: "Confirm your decision",
		confirmBodyTitle: "You are deciding on one transaction only",
		confirmBodyMeta: "Not the whole sheet. Remaining transactions stay in your queue.",
		confirmWarning:
			"Approving posts this decision immediately. Make sure the amounts and proof on the receipt match what you can see in the preview.",
		confirmNoteLabel: "Optional note to the purchaser",
		confirmNoteHint:
			"Appears in the history. Use it only when you want to add context; approvals never require a note.",
		confirmApprove: "Confirm approve",
		confirmReject: "Reject transaction",
		confirmReturn: "Return for correction",
		confirmCancel: "Cancel",
		confirmSubmitting: "Recording decision…",
		confirmFooterNote: "Posts immediately · applies to this transaction only",
		rejectWarning:
			"Rejecting is recorded immediately. Explain what is wrong so the purchaser can correct it — no partial or sheet-wide rejection is possible.",
		rejectLabel: "Why are you rejecting this transaction?",
		rejectHint: "Keep it specific and actionable — the note goes to the purchaser and into the sheet history.",
		errorLabel: "Explanation required",
		errorText:
			"A reject needs a short explanation so the purchaser can fix the problem. The sheet stays in the queue until you finish the decision.",
		errorShort: "Add an explanation before rejecting.",
		errorReturnShort: "Add an explanation before returning.",
		rejectTitle: "Reject — explanation required",
		rejectPlaceholder: "What is missing or wrong? e.g. receipt shows a different amount.",
		returnTitle: "Return transaction for correction?",
		returnWarning:
			"Returning is recorded immediately. Explain what the purchaser must fix — no partial or sheet-wide return is possible.",
		returnLabel: "Why are you returning this transaction?",
		returnHint: "Explain the correction clearly — the note goes to the purchaser and into the sheet history.",
		returnPlaceholder:
			"What should the purchaser correct? e.g. re-upload the itemised receipt, or add the sponsor memo.",
		detailsEyebrow: "Full details",
		detailsClose: "Close",
		decideLabel: "Decide this transaction",
		detailsNote:
			"Full-detail inspection. You can decide from here or from the quick-review card — both use the same single-transaction workflow and rules.",
		detailHistory: "History",
		detailAbout: "Description",
		detailDocs: "Attachments",
		detailSheet: "Sheet",
		detailPurchaser: "Purchased by",
		detailMerchant: "Merchant",
		detailPaid: "Paid on",
		detailMethod: "Payment method",
		detailCategory: "Category",
		detailCostCenter: "Cost center",
		detailAmount: "Amount",
		detailStatus: "Status",
		detailPreviewNote: "Preview opens without leaving the queue overlay stack.",
		previewEyebrow: "Document preview",
		previewClose: "Close",
		previewDownload: "Download",
		previewNavPrev: "Previous",
		previewNavNext: "Next",
		unsupportedTitle: "Inline preview is not available for this file type",
		unsupportedText:
			"This format opens outside the browser (e.g. PPTX, DOCX, XLSX). Download the file to inspect it, or archive it locally after deciding. The design keeps you on the transaction either way.",
		downloadMeta: "PowerPoint · 1.2 MB",
		visited: "Viewed",
		toastText:
			"Decisions stay visible in the flow. The sheet remains in the queue until every transaction is decided.",
		viewSwitchNote: "state switch",
	},
	fa: {
		dir: "rtl",
		toolTitle: "مدیر مرکز هزینه · بازبینی سریع درون‌خطی",
		toolBrand: "پیشنهاد طراحی #۰۰۶",
		toolNote: "ماکاپ صرفاً طراحی · داده‌ها نمایشی · پیاده‌سازی نیست",
		segState: "وضعیت",
		segDevice: "نمایش",
		segLang: "زبان",
		states: [
			["collapsed", "صف بسته"],
			["expanded", "بازبینی سریع"],
			["doc", "پیش‌نمایش مدرک"],
			["unsupported", "فرمت پشتیبانی‌نشده"],
			["details", "جزئیات کامل"],
			["confirm", "تأیید تصمیم"],
			["return", "تأیید بازگشت"],
			["error", "رد بدون توضیح"],
			["submitting", "در حال ثبت"],
			["completed", "تصمیم ثبت شد"],
		],
		devices: [
			["desktop", "دسکتاپ"],
			["mobile", "موبایل"],
		],
		langs: [
			["en", "English"],
			["fa", "فارسی"],
		],
		crumbs: ["تن‌خواه", "بازبینی مرکز هزینه"],
		simPill: "ماکاپ طراحی",
		nav: [
			["home", "خانه", null],
			["sheets", "برگه‌های من", null],
			["approvals", "تأییدها", 3],
			["centers", "مراکز هزینه", null],
			["reports", "گزارش‌ها", null],
		],
		userRole: "مدیر مرکز هزینه",
		pageEyebrow: "«مرکز هزینه شمال»",
		pageTitle: "صف مدیریت",
		pageSubtitle:
			"تراکنش‌های ارجاع‌شده به «مرکز هزینه شمال» همین‌جا، درون صف و بدون خروج از زمینه، بازبینی می‌شوند.",
		roleBadge: "مدیر مرکز هزینه",
		bannerTitle: "شما به‌نمای «مرکز هزینه شمال» تصمیم می‌گیرید",
		bannerText:
			"هر تراکنش جداگانه تصمیم می‌گیرد. این فضای کاری هرگز کل برگه را یکجا تأیید، بازگشت یا رد نمی‌کند.",
		bannerLink: "خط‌مشی بازبینی",
		statSheets: "برگه‌های در انتظار تصمیم",
		statSheetsHint: "تازه‌ترین ارسال در بالا",
		statTx: "تراکنش‌های در انتظار تصمیم",
		statTxHint: "یک برگه تصمیم‌گیری نیمه‌تمام دارد",
		queueTitle: "تراکنش‌های مرتبط",
		queueDesc:
			"چینش متمرکز به سبک Manus: فقط برگه‌های مرتبط با مرکز هزینه، گروه‌شده زیر برگه، با تراکنش‌هایی که باید درباره‌شان تصمیم بگیرید، بازشده در همان‌جا.",
		searchPlaceholder: "جست‌وجوی برگه، خریدار یا شناسه…",
		searchLabel: "جست‌وجو در صف",
		availabilityNote:
			"فقط داده‌های نمایشی. عنوان برگه، خریدار و جمع کل، فیلدهای هدفِ طراحی (FR-006-002 و FR-006-003) هستند — هنوز در API تحویل نشده‌اند و اینجا به‌صورت طراحی نهایی نمایش داده شده‌اند.",
		colSheet: "برگه",
		colPurchaser: "خریدار",
		colTx: "تراکنش",
		colTotal: "جمع",
		colStatus: "وضعیت",
		colSubmitted: "ارسال",
		sortLabel: "مرتب‌سازی",
		sortOptions: ["تازه‌ترین ارسال", "مبلغ · نزولی", "مبلغ · صعودی"],
		tabs: [
			["همه برگه‌ها", 3],
			["در انتظار تصمیم", 2],
			["در جریان", 1],
		],
		pillAwaiting: "در انتظار بازبینی",
		pillInProgress: "در جریان",
		pillApproved: "تأیید شده",
		pillRejected: "رد شده",
		pillReturned: "بازگشت داده شده",
		statTxLabel: "تراکنش",
		statTotalLabel: "جمع",
		chevronOpen: "باز کردن بازبینی سریع برای",
		chevronClose: "بستن بازبینی سریع برای",
		bodyEyebrow: "بازبینی سریع",
		purchaserLabel: "خریدار",
		scopeLabel: "در محدوده",
		collapseLink: "بستن",
		footTitle: "تصمیم برای کل برگه ممکن نیست.",
		footText:
			"این طراحی دروازه تصمیم را تراکنش‌به‌تراکنش نگه می‌دارد. برگه فقط زمانی از صف خارج می‌شود که همه تراکنش‌های مرتبط آن تصمیم داشته باشند. اقدام برگه‌کل عمداً وجود ندارد.",
		openDetails: "جزئیات کامل",
		openPreview: "پیش‌نمایش",
		approve: "تأیید",
		reject: "رد",
		returnTx: "بازگشت",
		decidedNote: "تأیید توسط شما",
		decidedFor: "برای",
		undo: "واچینش", // design placeholder word for revert — intentionally unused for now
		docsLabel: "پیوست‌ها",
		docsMore: "بیشتر",
		docsOffscreen: "باز کردن پیش‌نمایش مدرک",
		detailsOnly: "پیش‌نمایش این نوع ممکن نیست — جزئیات کامل را باز کنید",
		confirmTitle: "تأیید تراکنش؟",
		confirmTitleOff: "تأیید تصمیم شما",
		confirmBodyTitle: "فقط درباره‌ی یک تراکنش تصمیم می‌گیرید",
		confirmBodyMeta: "نه کل برگه. تراکنش‌های باقی‌مانده در صف شما می‌مانند.",
		confirmWarning:
			"تأیید بلافاصله ثبت می‌شود. مطمئن شوید مبالغ و مدرک روی رسید با آنچه در پیش‌نمایش می‌بینید هم‌خوانی دارد.",
		confirmNoteLabel: "یادداشت اختیاری برای خریدار",
		confirmNoteHint:
			"در تاریخچه ثبت می‌شود. فقط برای افزودن توضیح استفاده کنید؛ تأیید به یادداشت نیاز ندارد.",
		confirmApprove: "ثبت تأیید",
		confirmReject: "رد تراکنش",
		confirmReturn: "بازگشت برای اصلاح",
		confirmCancel: "انصراف",
		confirmSubmitting: "در حال ثبت تصمیم…",
		confirmFooterNote: "ثبت فوری · فقط برای همین تراکنش",
		rejectWarning:
			"رد کردن بلافاصله ثبت می‌شود. روشن توضیح دهید چه چیزی نادرست است تا خریدار بتواند اصلاح کند — رد جزئی یا رد کل برگه ممکن نیست.",
		rejectLabel: "چرا این تراکنش را رد می‌کنید؟",
		rejectHint: "روشن و قابل‌اقدام بنویسید — این یادداشت برای خریدار و در تاریخچه برگه ثبت می‌شود.",
		errorLabel: "توضیح لازم است",
		errorText:
			"رد کردن نیاز به توضیح کوتاه دارد تا خریدار بتواند مشکل را برطرف کند. برگه تا تکمیل تصمیم در صف می‌ماند.",
		errorShort: "پیش از رد کردن، توضیحی بنویسید.",
		errorReturnShort: "پیش از بازگرداندن، توضیحی بنویسید.",
		rejectTitle: "رد — توضیح لازم است",
		rejectPlaceholder: "چه چیزی کم یا نادرست است؟ مثلاً مبلغ رسید متفاوت است.",
		returnTitle: "بازگشت تراکنش برای اصلاح؟",
		returnWarning:
			"بازگشت دادن بلافاصله ثبت می‌شود. روشن توضیح دهید خریدار چه چیزی را اصلاح کند — بازگشت جزئی یا بازگشت کل برگه ممکن نیست.",
		returnLabel: "چرا این تراکنش را بازمی‌گردانید؟",
		returnHint: "اصلاح را روشن بنویسید — این یادداشت برای خریدار و در تاریخچه برگه ثبت می‌شود.",
		returnPlaceholder: "خریدار چه چیزی را اصلاح کند؟ مثلاً رسید تفصیلی یا یادداشت حامی مالی را دوباره بارگذاری کند.",
		detailsEyebrow: "جزئیات کامل",
		detailsClose: "بستن",
		decideLabel: "تصمیم دربارهٔ این تراکنش",
		detailsNote:
			"بازبینی با جزئیات کامل. از همین‌جا یا از کارت بازبینی سریع تصمیم بگیرید — هر دو از یک گردش‌کار تک‌تراکنشی و قوانین یکسان پیروی می‌کنند.",
		detailHistory: "تاریخچه",
		detailAbout: "توضیحات",
		detailDocs: "پیوست‌ها",
		detailSheet: "برگه",
		detailPurchaser: "خریدار",
		detailMerchant: "فروشنده",
		detailPaid: "تاریخ پرداخت",
		detailMethod: "روش پرداخت",
		detailCategory: "دسته",
		detailCostCenter: "مرکز هزینه",
		detailAmount: "مبلغ",
		detailStatus: "وضعیت",
		detailPreviewNote: "پیش‌نمایش بدون خروج از پشته صف باز می‌شود.",
		previewEyebrow: "پیش‌نمایش مدرک",
		previewClose: "بستن",
		previewDownload: "دانلود",
		previewNavPrev: "قبلی",
		previewNavNext: "بعدی",
		unsupportedTitle: "پیش‌نمایش درون‌خطی برای این نوع فایل موجود نیست",
		unsupportedText:
			"این قالب خارج از مرورگر باز می‌شود (مثل PPTX، DOCX، XLSX). برای بررسی، فایل را دانلود کنید یا پس از تصمیم، آن را بایگانی کنید. طراحی در هر حال شما را روی تراکنش نگه می‌دارد.",
		downloadMeta: "پاورپوینت · ۱٫۲ مگابایت",
		visited: "دیده شده",
		toastText:
			"تصمیم‌ها در جریان کار قابل‌مشاهده می‌مانند. برگه تا تصمیم‌گرفتن روی همه تراکنش‌ها در صف می‌ماند.",
		viewSwitchNote: "تغییر وضعیت",
	},
};

/* ---------------------------------------------------------------------------
 * Minimal inline icon set (stroke SVG, 24-grid)
 * ------------------------------------------------------------------------- */

const I = (name) => {
	const paths = {
		search: '<circle cx="11" cy="11" r="6.5"/><path d="M20.5 20.5 15.7 15.7"/>',
		chev: '<path d="m5 9 7 7 7-7"/>',
		chevR: '<path d="m9 5 7 7-7 7"/>',
		file: '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/>',
		image:
			'<rect x="3.5" y="5" width="17" height="14" rx="2.5"/><circle cx="9" cy="10" r="1.7"/><path d="M4.5 17.5 10 12.5l4.5 4 2-1.8 3 2.5"/>',
		pdf: '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/><path d="M9.5 14.5h5M9.5 17h5"/>',
		xls: '<path d="M7 3h7l4 4v14H7z"/><path d="M10 13h4M10 16h4M12 13v6"/>',
		text: '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/><path d="M9.5 12h5M9.5 15h5M9.5 18h3"/>',
		pptx: '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/><rect x="9.5" y="12.5" width="5" height="4" rx="1"/>',
		unknown: '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/><circle cx="11.5" cy="14" r="3"/><path d="M11.5 15v2"/>',
		close: '<path d="M6 6l12 12M18 6 6 18"/>',
		check: '<path d="m5 13 4.5 4.5L19 7.5"/>',
		alert: '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16.2v.3"/>',
		download: '<path d="M12 4v10M8 10l4 4 4-4"/><path d="M5 19h14"/>',
		home: '<path d="m4 11 8-7 8 7v9H4z"/>',
		sheetIcon: '<rect x="5" y="4" width="14" height="16" rx="2"/><path d="M9 9h6M9 13h6M9 17h3"/>',
		shield: '<path d="M12 3.5 19 6v6c0 4.4-3 7-7 8.5C8 19 5 16.4 5 12V6z"/><path d="m9.3 12 2 2 3.6-4"/>',
		folder: '<path d="M4 7h5l2 2h9v10H4z"/>',
		chart: '<path d="M5 20V10M11 20V5M17 20v-7"/>',
		receipt: '<path d="M6 3h12v18l-2.5-1.6L13 21l-2.5-1.6L8 21l-2-1.6z"/><path d="M9 8h6M9 11.5h6M9 15h4"/>',
		card: '<rect x="3.5" y="6" width="17" height="12" rx="2"/><path d="M3.5 10h17"/>',
		clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7v5.4l3.6 2"/>',
		user: '<circle cx="12" cy="8.5" r="3.4"/><path d="M5.5 19c1.3-3 4-4.4 6.5-4.4s5.2 1.4 6.5 4.4"/>',
		back: '<path d="M14 6l-6 6 6 6"/>',
		route: '<circle cx="6.5" cy="6" r="2.5"/><circle cx="17.5" cy="18" r="2.5"/><path d="M9 6h5a3.5 3.5 0 0 1 0 7h-6"/>',
	};
	return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.file}</svg>`;
};

/* ---------------------------------------------------------------------------
 * Illustrative artwork — locally generated SVG "receipt" / "invoice" images
 * (data URIs; no network requests, safe for offline screenshots)
 * ------------------------------------------------------------------------- */

function artReceipt(seed, hue) {
	const h = hue ?? (190 + (seed * 47) % 60);
	const rows = [];
	let y = 96;
	for (let i = 0; i < 7; i++) {
		const w = 150 - ((seed * 31 + i * 37) % 90);
		rows.push(
			`<rect x="46" y="${y}" width="${w}" height="7" rx="3.5" fill="#aebfb5" opacity="${0.6 + ((seed + i) % 3) * 0.14}"/>`
		);
		y += 26;
	}
	const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="420" height="540" viewBox="0 0 420 540">
	<rect width="420" height="540" fill="#fbfdfb"/>
	<rect x="26" y="22" width="368" height="496" rx="12" fill="#ffffff" stroke="hsl(${h} 18% 78%)" stroke-width="2"/>
	<rect x="26" y="22" width="368" height="64" rx="12" fill="hsl(${h} 42% 30%)" opacity="0.86"/>
	<rect x="52" y="44" width="140" height="14" rx="7" fill="#ffffff" opacity="0.9"/>
	<rect x="52" y="64" width="88" height="9" rx="4.5" fill="#ffffff" opacity="0.55"/>
	<rect x="292" y="30" width="76" height="76" rx="16" fill="hsl(${h} 55% 92%)"/>
	<path d="M312 74h36M330 56v36" stroke="hsl(${h} 40% 36%)" stroke-width="6" stroke-linecap="round"/>
	${rows.join("")}
	<rect x="46" y="298" width="328" height="1.5" fill="#d7dfda"/>
	<rect x="200" y="396" width="86" height="10" rx="5" fill="#8fa39a"/>
	<rect x="46" y="440" width="124" height="12" rx="6" fill="#8fa39a" opacity="0.6"/>
	<g opacity="0.85">${Array.from({ length: 26 }, (_, i) =>
		`<text x="${68 + (i % 14) * 21}" y="${448 + Math.floor(i / 14) * 34}" font-family="monospace" font-size="13" fill="#5c6b63">${"ABCDEFGHJKLMNPQRSTUVWXYZ0123456789"[(seed * 7 + i * 13) % 34]}</text>`
	).join("")}</g>
	</svg>`;
	return "data:image/svg+xml;utf8," + encodeURIComponent(svg);
}

function artInvoice(seed, hue) {
	const h = hue ?? 260;
	const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="500" height="640" viewBox="0 0 500 640">
	<rect width="500" height="640" fill="#f5f6fa"/>
	<rect x="40" y="30" width="420" height="580" rx="10" fill="#ffffff" stroke="hsl(${h} 20% 80%)" stroke-width="2"/>
	<rect x="72" y="70" width="180" height="18" rx="9" fill="hsl(${h} 40% 45%)" opacity="0.75"/>
	<rect x="72" y="100" width="110" height="10" rx="5" fill="#c4c8d4"/>
	${Array.from({ length: 9 }, (_, i) => {
		const y = 170 + i * 34;
		return `<rect x="72" y="${y}" width="${190 - (i * 9) % 70}" height="8" rx="4" fill="#b8bfd1"/>` +
			`<rect x="${340}" y="${y}" width="${62 - (i * 5) % 24}" height="8" rx="4" fill="#9aa4bd"/>`;
	}).join("")}
	<rect x="72" y="500" width="356" height="1.5" fill="#b8bfd1"/>
	<rect x="252" y="530" width="176" height="24" rx="12" fill="hsl(${h} 55% 90%)"/>
	<rect x="272" y="540" width="60" height="4" rx="2" fill="hsl(${h} 30% 40%)"/>
	<rect x="72" y="584" width="96" height="9" rx="4.5" fill="#aeb4c4" opacity="0.7"/>
	</svg>`;
	return "data:image/svg+xml;utf8," + encodeURIComponent(svg);
}

/* ---------------------------------------------------------------------------
 * Illustrative data (Manager view · Maya Chen · Cost Center North)
 * Amounts and names are invented. Dates are fixed strings per locale.
 * ------------------------------------------------------------------------- */

const DATA = {
	en: {
		manager: { initials: "MC", name: "Maya Chen", role: "Cost center manager" },
		sheets: [
			{
				id: "SHEET-0251",
				title: "Client visit — Düsseldorf",
				purchaser: { name: "Daniel Okafor", role: "Account engineer", initials: "DO" },
				purchasedBy: "Daniel Okafor",
				submitted: "28 Oct 2026 · 09:42",
				aggregate: "3 tx · awaiting",
				status: "awaiting",
				txTotal: "2,486.50",
				currency: "USD",
				txIds: ["TX-10842", "TX-10843", "TX-10844"],
				txs: [
					{
						id: "TX-10842",
						icon: "receipt",
						title: "Client dinner — Meridian Logistics",
						desc:
							"Dinner with the Meridian purchasing team after the service-contract checkpoint. Costs per head within the client-entertainment ceiling.",
						amount: "684.00",
						currency: "USD",
						status: "awaiting",
						meta: {
							merchant: "Garden Hall Restaurant",
							paid: "27 Oct 2026",
							method: "Corporate card ···4102",
							category: "Client entertainment",
						},
						decor: "policy: 2 guest names verified",
						docs: [
							{ id: "EV-501", name: "gardenhall-receipt.jpg", kind: "image", ext: "JPG", art: artReceipt(3, 150) },
							{ id: "EV-502", name: "meridian-attendance.pdf", kind: "pdf", ext: "PDF", art: artInvoice(2, 150) },
						],
						history: [
							["Submitted by Daniel Okafor", "28 Oct 2026 · 09:42"],
							["Routed to Cost Center North", "28 Oct 2026 · 09:47"],
							["Opened in queue", "Your quick review — today"],
						],
						description:
							"3 attendees signed on attendance PDF (Meridian side: procurement lead, contract manager). Card slip and itemised receipt attached. Gratuity line matches service policy KB-114.",
					},
					{
						id: "TX-10843",
						icon: "card",
						title: "Airport transfer — DUS ⇄ hotel",
						desc:
							"Fixed-price transfer for the two visiting Meridian staff on the same trip (invoice shows round trip and waiting time).",
						amount: "562.50",
						currency: "USD",
						status: "awaiting",
						meta: {
							merchant: "City Cars NL",
							paid: "27 Oct 2026",
							method: "Corporate card ···4102",
							category: "Travel · ground",
						},
						decor: "route matches trip SHEET-0247",
						docs: [
							{ id: "EV-503", name: "citycars-invoice.pdf", kind: "pdf", ext: "PDF", art: artInvoice(5, 150) },
							{ id: "EV-504", name: "pickup-sign-photo.jpg", kind: "image", ext: "JPG", art: artReceipt(8, 150) },
						],
						history: [
							["Submitted by Daniel Okafor", "28 Oct 2026 · 09:42"],
							["Invoice number linked (INV-88120)", "28 Oct 2026 · 09:44"],
							["Opened in queue", "Your quick review — today"],
						],
						description:
							"No-show waiver printed on invoice line 3. Waiting-time clause (max 45 min) invoked; visible on PDF. Amount matches accounting export.",
					},
					{
						id: "TX-10844",
						icon: "sheetIcon",
						title: "Client workshop venue — half day",
						desc:
							"Half-day design workshop room rental for the Meridian eval team. Quote, changed agenda and footage deck attached.",
						amount: "1,240.00",
						currency: "USD",
						status: "awaiting",
						meta: {
							merchant: "Studio Loft Düsseldorf",
							paid: "25 Oct 2026",
							method: "Bank transfer 7d",
							category: "Meetings",
						},
						decor: "quote SV-2331 vs invoice",
						docs: [
							{ id: "EV-505", name: "studio-loft-deck.pptx", kind: "pptx", ext: "PPTX", art: null },
							{ id: "EV-506", name: "loft-quote-vs-invoice.pdf", kind: "pdf", ext: "PDF", art: artInvoice(11, 150) },
						],
						history: [
							["Submitted by Daniel Okafor", "28 Oct 2026 · 09:42"],
							["Quote SV-2331 attached", "26 Oct 2026 · 17:10"],
							["Opened in queue", "Your quick review — today"],
						],
						description:
							"Invoice shows the agreed half-day rate plus catering add-on cancelled on 24 Oct. Deck includes photo comparison used for change-order discussion.",
					},
				],
			},
			{
				id: "SHEET-0252",
				title: "Team offsite — catering",
				purchaser: { name: "Priya Nair", role: "Staff engineer", initials: "PN" },
				purchasedBy: "Priya Nair",
				submitted: "24 Oct 2026 · 14:05",
				aggregate: "1 of 2 decided",
				status: "inProgress",
				txTotal: "2,336.40",
				currency: "USD",
				txIds: ["TX-10829", "TX-10831"],
				txs: [
					{
						id: "TX-10829",
						icon: "sheetIcon",
						title: "Venue deposit — offsite room",
						desc: "Refundable room deposit. Missing the sponsor confirmation asked for in policy RF-12; being fixed by the purchaser.",
						amount: "950.00",
						currency: "USD",
						status: "returned",
						returnNote: "Returned · missing sponsor approval (RF-12). Resubmit with sponsor memo, then it returns to your queue.",
						meta: {
							merchant: "Riverside Hall",
							paid: "22 Oct 2026",
							method: "Bank transfer 7d",
							category: "Meetings",
						},
						docs: [{ id: "EV-489", name: "riverside-receipt.jpg", kind: "image", ext: "JPG", art: artReceipt(14, 288) }],
						history: [
							["Submitted by Priya Nair", "24 Oct 2026 · 14:05"],
							["Returned by Maya Chen", "24 Oct 2026 · 16:20 — explanation recorded"],
						],
						description:
							"Deposit held against card-on-file; cancellation window 72 h. Sponsor memo requested from engineering budget owner.",
					},
					{
						id: "TX-10831",
						icon: "receipt",
						title: "Catering — day budget",
						desc: "Two-day catering package capped at per-head budget PA-07. Receipt sum matches number of attendees.",
						amount: "1,386.40",
						currency: "USD",
						status: "approved",
						decidedNote: "Approved",
						decidedMeta: "25 Oct 2026 · you — with note",
						meta: {
							merchant: "Blandford Catering Co.",
							paid: "23 Oct 2026",
							method: "Corporate card ··8917",
							category: "Catering",
						},
						docs: [{ id: "EV-491", name: "blandford-invoice.pdf", kind: "pdf", ext: "PDF", art: artInvoice(17, 288) }],
						history: [
							["Submitted by Priya Nair", "24 Oct 2026 · 14:05"],
							["Approved by Maya Chen", "25 Oct 2026 · 08:55 — note sent to purchaser"],
						],
						description: "Per-head rate within PA-07 cap. VAT split printable; accountant already booked provisional entry.",
					},
				],
			},
			{
				id: "SHEET-0253",
				title: "Replacement router — meeting room 2",
				purchaser: { name: "Leo Grant", role: "Office manager", initials: "LG" },
				purchasedBy: "Leo Grant",
				submitted: "29 Oct 2026 · 08:15",
				aggregate: "1 tx · awaiting",
				status: "awaiting",
				txTotal: "186.90",
				currency: "USD",
				txIds: ["TX-10847"],
				txs: [
					{
						id: "TX-10847",
						icon: "card",
						title: "Mesh router + install",
						desc: "Critical replace after meeting-room outages on 28 Oct. Old unit serial photographed before RMA.",
						amount: "186.90",
						currency: "USD",
						status: "awaiting",
						meta: {
							merchant: "Northgate Supply",
							paid: "28 Oct 2026",
							method: "Purchase card",
							category: "Office",
						},
						decor: "tie to IT outage ticket 4417",
						docs: [{ id: "EV-509", name: "northgate-receipt.jpg", kind: "image", ext: "JPG", art: artReceipt(21, 288) }],
						history: [
							["Submitted by Leo Grant", "29 Oct 2026 · 08:15"],
							["Incident 4417 linked", "29 Oct 2026 · 08:18"],
							["Opened in queue", "Not yet opened"],
						],
						description: "Spare from local stock at 9% expedite surcharge; compatible with meeting-room mesh; no approval escalation needed under SP-3.",
					},
				],
			},
		],
	},
	fa: {
		manager: { initials: "م‌چ", name: "مایا چن", role: "مدیر مرکز هزینه" },
		sheets: [
			{
				id: "SHEET-0251",
				title: "بازدید مشتری — دوسلدورف",
				purchaser: { name: "دانیل اوکفور", role: "مهندس حساب‌ها", initials: "DO" },
				purchasedBy: "دانیل اوکفور",
				submitted: "۲۸ اکتبر ۲۰۲۶ · ۰۹:۴۲",
				aggregate: "۳ تراکنش · در انتظار",
				status: "awaiting",
				txTotal: "۲٬۴۸۶٫۵۰",
				currency: "$",
				txIds: ["TX-10842", "TX-10843", "TX-10844"],
				txs: [
					{
						id: "TX-10842",
						icon: "receipt",
						title: "شام مشتری — شرکت مریدیان لوجستیک",
						desc:
							"شام با تیم خرید مریدیان پس از نقطه بازبینی قرارداد خدمات. هزینه سرشکن در سقفِ پذیرایی از مشتری است.",
						amount: "۶۸۴٫۰۰",
						currency: "$",
						status: "awaiting",
						meta: {
							merchant: "رستوران گاردن هال",
							paid: "۲۷ اکتبر ۲۰۲۶",
							method: "کارت شرکت ···۴۱۰۲",
							category: "پذیرایی مشتری",
						},
						decor: "تأیید دو نام مهمان در رسید",
						docs: [
							{ id: "EV-501", name: "gardenhall-receipt.jpg", kind: "image", ext: "JPG", art: artReceipt(3, 150) },
							{ id: "EV-502", name: "meridian-attendance.pdf", kind: "pdf", ext: "PDF", art: artInvoice(2, 150) },
						],
						history: [
							["ارسال توسط دانیل اوکفور", "۲۸ اکتبر ۲۰۲۶ · ۰۹:۴۲"],
							["ارجاع به مرکز هزینه شمال", "۲۸ اکتبر ۲۰۲۶ · ۰۹:۴۷"],
							["باز شدن در صف", "بازبینی سریع شما — امروز"],
						],
						description:
							"سه شرکت‌کننده روی PDF حضور امضا کرده‌اند (سمت مریدیان: مسئول خرید، مدیر قرارداد). رسید کارت و صورت تفصیلی پیوست است. حق خدمت با خط‌مشی KB-114 هم‌خوان است.",
					},
					{
						id: "TX-10843",
						icon: "card",
						title: "ترانسفر فرودگاه — DUS ⇄ هتل",
						desc:
							"ترانسفر با قیمت ثابت برای دو کارشناس مریدیان در همین سفر (فاکتور رفت‌وبرگشت و زمان انتظار را نشان می‌دهد).",
						amount: "۵۶۲٫۵۰",
						currency: "$",
						status: "awaiting",
						meta: {
							merchant: "سیتی کارز NL",
							paid: "۲۷ اکتبر ۲۰۲۶",
							method: "کارت شرکت ···۴۱۰۲",
							category: "سفر · زمینی",
						},
						decor: "مسیر با برگه SHEET-0247 هم‌خوان است",
						docs: [
							{ id: "EV-503", name: "citycars-invoice.pdf", kind: "pdf", ext: "PDF", art: artInvoice(5, 150) },
							{ id: "EV-504", name: "pickup-sign-photo.jpg", kind: "image", ext: "JPG", art: artReceipt(8, 150) },
						],
						history: [
							["ارسال توسط دانیل اوکفور", "۲۸ اکتبر ۲۰۲۶ · ۰۹:۴۲"],
							["اتصال شماره فاکتور (INV-88120)", "۲۸ اکتبر ۲۰۲۶ · ۰۹:۴۴"],
							["باز شدن در صف", "بازبینی سریع شما — امروز"],
						],
						description:
							"بند عدم حضور در سطر ۳ فاکتور چاپ شده است. بند زمان انتظار (حداکثر ۴۵ دقیقه) اعمال شده و روی PDF دیده می‌شود. مبلغ با خروجی حسابداری برابر است.",
					},
					{
						id: "TX-10844",
						icon: "sheetIcon",
						title: "سالن کارگاه مشتری — نیم‌روز",
						desc: "اجاره سالن نیم‌روزه کارگاه طراحی برای تیم ارزیابی مریدیان. پیش‌فاکتور و آجندا و اسلایدها پیوست است.",
						amount: "۱٬۲۴۰٫۰۰",
						currency: "$",
						status: "awaiting",
						meta: {
							merchant: "استودیو لافت دوسلدورف",
							paid: "۲۵ اکتبر ۲۰۲۶",
							method: "انتقال بانکی ۷ روزه",
							category: "جلسات",
						},
						decor: "سنجش پیش‌فاکتور SV-2331 با فاکتور",
						docs: [
							{ id: "EV-505", name: "studio-loft-deck.pptx", kind: "pptx", ext: "PPTX", art: null },
							{ id: "EV-506", name: "loft-quote-vs-invoice.pdf", kind: "pdf", ext: "PDF", art: artInvoice(11, 150) },
						],
						history: [
							["ارسال توسط دانیل اوکفور", "۲۸ اکتبر ۲۰۲۶ · ۰۹:۴۲"],
							["پیوست پیش‌فاکتور SV-2331", "۲۶ اکتبر ۲۰۲۶ · ۱۷:۱۰"],
							["باز شدن در صف", "بازبینی سریع شما — امروز"],
						],
						description:
							"فاکتور نرخ نیم‌روز توافق‌شده به‌علاوه بخش پذیراییِ لغوشده در ۲۴ اکتبر را نشان می‌دهد. اسلایدها مقایسه تصویری مورد بحث تغییر سفارش را شامل می‌شود.",
					},
				],
			},
			{
				id: "SHEET-0252",
				title: "گردهمایی تیم — پذیرایی",
				purchaser: { name: "پریا نایر", role: "مهندس ارشد", initials: "PN" },
				purchasedBy: "پریا نایر",
				submitted: "۲۴ اکتبر ۲۰۲۶ · ۱۴:۰۵",
				aggregate: "۱ از ۲ تصمیم‌گرفته",
				status: "inProgress",
				txTotal: "۲٬۳۳۶٫۴۰",
				currency: "$",
				txIds: ["TX-10829", "TX-10831"],
				txs: [
					{
						id: "TX-10829",
						icon: "sheetIcon",
						title: "پیش‌پرداخت سالن گردهمایی",
						desc: "پیش‌پرداخت برگرداندنی سالن. تأیید حامی مالی خواسته‌شده در خط‌مشی RF-12 پیوست نیست؛ خریدار در حال اصلاح است.",
						amount: "۹۵۰٫۰۰",
						currency: "$",
						status: "returned",
						returnNote: "بازگشت · تأیید حامی مالی (RF-12) پیوست نشده است. همراه یادداشت حامی دوباره ارسال شود تا به صف شما برگردد.",
						meta: {
							merchant: "سالن ریورساید",
							paid: "۲۲ اکتبر ۲۰۲۶",
							method: "انتقال بانکی ۷ روزه",
							category: "جلسات",
						},
						docs: [{ id: "EV-489", name: "riverside-receipt.jpg", kind: "image", ext: "JPG", art: artReceipt(14, 288) }],
						history: [
							["ارسال توسط پریا نایر", "۲۴ اکتبر ۲۰۲۶ · ۱۴:۰۵"],
							["بازگشت توسط مایا چن", "۲۴ اکتبر ۲۰۲۶ · ۱۶:۲۰ — توضیح ثبت شد"],
						],
						description: "پیش‌پرداخت روی کارت اعتباری بلوکه شده است؛ مهلت فسخ ۷۲ ساعت. یادداشت حامی از بودجه مهندسی درخواست شده است.",
					},
					{
						id: "TX-10831",
						icon: "receipt",
						title: "پذیرایی — بودجه روز",
						desc: "بسته پذیرایی دو روزه با سقف سرشکن PA-07. جمع رسید با تعداد شرکت‌کنندگان هم‌خوان است.",
						amount: "۱٬۳۸۶٫۴۰",
						currency: "$",
						status: "approved",
						decidedNote: "تأیید شده",
						decidedMeta: "۲۵ اکتبر ۲۰۲۶ · شما — همراه یادداشت",
						meta: {
							merchant: "شرکت کیترینگ بلندفرد",
							paid: "۲۳ اکتبر ۲۰۲۶",
							method: "کارت شرکت ··۸۹۱۷",
							category: "پذیرایی",
						},
						docs: [{ id: "EV-491", name: "blandford-invoice.pdf", kind: "pdf", ext: "PDF", art: artInvoice(17, 288) }],
						history: [
							["ارسال توسط پریا نایر", "۲۴ اکتبر ۲۰۲۶ · ۱۴:۰۵"],
							["تأیید توسط مایا چن", "۲۵ اکتبر ۲۰۲۶ · ۰۸:۵۵ — یادداشت برای خریدار ثبت شد"],
						],
						description: "نرخ سرشکن زیر سقف PA-07. تفکیک مالیات چاپ‌پذیر است؛ حسابداری سند موقت را ثبت کرده است.",
					},
				],
			},
			{
				id: "SHEET-0253",
				title: "جایگزینی روتر — اتاق جلسات ۲",
				purchaser: { name: "لئو گرانت", role: "مدیر دفتر", initials: "LG" },
				purchasedBy: "لئو گرانت",
				submitted: "۲۹ اکتبر ۲۰۲۶ · ۰۸:۱۵",
				aggregate: "۱ تراکنش · در انتظار",
				status: "awaiting",
				txTotal: "۱۸۶٫۹۰",
				currency: "$",
				txIds: ["TX-10847"],
				txs: [
					{
						id: "TX-10847",
						icon: "card",
						title: "روتر مش + نصب",
						desc: "جایگزینی بحرانی پس از قطعی‌های اتاق جلسات در ۲۸ اکتبر. سریال قطعه قدیمی پیش از مرجوعی عکس‌برداری شده است.",
						amount: "۱۸۶٫۹۰",
						currency: "$",
						status: "awaiting",
						meta: {
							merchant: "نورث‌گیت ساپلای",
							paid: "۲۸ اکتبر ۲۰۲۶",
							method: "کارت خرید",
							category: "دفتری",
						},
						decor: "اتصال به تیکت قطعی IT ۴۴۱۷",
						docs: [{ id: "EV-509", name: "northgate-receipt.jpg", kind: "image", ext: "JPG", art: artReceipt(21, 288) }],
						history: [
							["ارسال توسط لئو گرانت", "۲۹ اکتبر ۲۰۲۶ · ۰۸:۱۵"],
							["اتصال حادثه ۴۴۱۷", "۲۹ اکتبر ۲۰۲۶ · ۰۸:۱۸"],
							["باز شدن در صف", "هنوز باز نشده"],
						],
						description: "قطعه از موجودی محلی با ۹٪ هزینه فورساری؛ سازگار با مش اتاق جلسات؛ طبق SP-3 نیازی به ارتقای سطح تأیید نیست.",
					},
				],
			},
		],
	},
};

/* state keys → default transaction / document to focus for capture states */
const FOCUS = {
	doc: { sheet: "SHEET-0251", tx: "TX-10842", docId: "EV-501" },
	unsupported: { sheet: "SHEET-0251", tx: "TX-10844", docId: "EV-505" },
	details: { sheet: "SHEET-0251", tx: "TX-10843" },
	confirm: { sheet: "SHEET-0251", tx: "TX-10842", kind: "approve" },
	return: { sheet: "SHEET-0251", tx: "TX-10842", kind: "return" },
	error: { sheet: "SHEET-0251", tx: "TX-10842", kind: "reject" },
	submitting: { sheet: "SHEET-0251", tx: "TX-10842", kind: "approve" },
	completed: { sheet: "SHEET-0251", tx: "TX-10842", kind: "approve" },
};

/* ---------------------------------------------------------------------------
 * Harness state + helpers
 * ------------------------------------------------------------------------- */

const params = new URLSearchParams(location.search);
const state = {
	lang: params.get("lang") === "fa" ? "fa" : "en",
	device: params.get("device") === "mobile" ? "mobile" : "desktop",
	view: params.get("view") || null, // { sheet, tx, overlay kind, docId, ... }
	capture: params.get("capture") === "1",
};
let lastExpandedId = null;

function iconFor(kind) {
	return { image: "image", pdf: "pdf", xls: "xls", text: "text", pptx: "pptx", docx: "pptx" }[kind] || "unknown";
}

function pill(status) {
	const t = COPY[state.lang];
	const map = {
		awaiting: ["pill--awaiting", t.pillAwaiting],
		inProgress: ["pill--awaiting", t.pillInProgress],
		approved: ["pill--approved", t.pillApproved],
		rejected: ["pill--rejected", t.pillRejected],
		returned: ["pill--returned", t.pillReturned],
	};
	const [cls, label] = map[status] || ["pill--neutral", status];
	return `<span class="pill ${cls}">${label}</span>`;
}

function money(currency, amount) {
	return `<span class="tx-card__currency">${currency}</span><span>${amount}</span>`;
}

/* queue order: newest submitted first (0253: 29 Oct, 0251: 28 Oct, 0252: 24 Oct) */
for (const locale of ["en", "fa"]) {
	const list = DATA[locale].sheets;
	DATA[locale].sheets = [
		list.find((s) => s.id === "SHEET-0253"),
		list.find((s) => s.id === "SHEET-0251"),
		list.find((s) => s.id === "SHEET-0252"),
	];
}

/* third attachment for TX-10842 so the "+N more" tile is demonstrable */
for (const locale of ["en", "fa"]) {
	const s = DATA[locale].sheets.find((x) => x.id === "SHEET-0251");
	const tx = s.txs[0];
	if (tx.docs.length < 3) {
		tx.docs.push({
			id: "EV-507",
			name: locale === "en" ? "attendance-scan-back.jpg" : "attendance-scan-back.jpg",
			kind: "image",
			ext: "JPG",
			art: artReceipt(6, 150),
		});
	}
}

/* ---------------------------------------------------------------------------
 * Renderers
 * ------------------------------------------------------------------------- */

function navItem([icon, label, badge]) {
	return `<button class="nav-item${icon === "shield" ? " is-active" : ""}">
		<span class="nav-item__icon">${I(icon)}</span>${label}
		${badge != null ? `<span class="nav-item__badge" aria-label="${badge}">${badge}</span>` : ""}
	</button>`;
}

function statCard({ label, value, hint, icon, iconCls, disabled, amount }) {
	const t = COPY[state.lang];
	return `<div class="stat${disabled ? " stat--disabled" : ""}">
		<div class="stat__top"><span class="stat__label">${label}</span><span class="stat__icon ${iconCls || ""}">${I(icon)}</span></div>
		<p class="stat__value ${amount ? "stat__value--amount" : ""}${disabled ? " stat__value--muted" : ""}">${value}</p>
		<span class="stat__hint">${hint}</span>
	</div>`;
}

function docThumb(doc, sheet, tx, moreCount) {
	const t = COPY[state.lang];
	if (moreCount != null) {
		return `<button class="doc-thumb doc-thumb--more" data-doc-more="${sheet.id}:${tx.id}"
			aria-label="${moreCount} ${t.docsMore}"><span>+${moreCount}</span><small>${t.docsMore}</small></button>`;
	}
	const art = doc.art
		? `<img class="doc-thumb__art" src="${doc.art}" alt="">`
		: `<span class="doc-thumb__art" style="display:grid;place-items:center;color:var(--pc-text-2)">${I(iconFor(doc.kind))}</span>`;
	return `<button class="doc-thumb" data-doc="${doc.id}" data-sheet="${sheet.id}" data-tx="${tx.id}"
		aria-label="${doc.name} — ${t.docsOffscreen}" title="${doc.name}">${art}<span class="doc-thumb__label">${doc.ext}</span></button>`;
}

function txCard(sheet, tx) {
	const t = COPY[state.lang];
	const decided = tx.status === "approved" || tx.status === "returned" || tx.status === "rejected";
	const docs = tx.docs;
	const overflow = Math.max(0, docs.length - 2);
	const thumbs = docs.slice(0, 2).map((d) => docThumb(d, sheet, tx)).join("");
	const moreTile = overflow > 0 ? docThumb(null, sheet, tx, overflow) : "";

	const noteBlock = decided
		? tx.status === "returned"
			? `<div class="tx-card__decided-note" style="border-color:rgb(111 67 201 / 18%);background:var(--pc-purple-soft);color:#4c2f8f">
				${I("route")}<span><strong>${t.pillReturned}</strong> — ${tx.returnNote}</span></div>`
			: `<div class="tx-card__decided-note">${I("check")}<span><strong>${t.decidedNote}${tx.decidedMeta ? " · " + tx.decidedMeta : ""}</strong></span></div>`
		: `<div class="tx-card__actions">
			<button class="btn btn--danger" data-decision="reject" data-sheet="${sheet.id}" data-tx="${tx.id}">${I("close")} ${t.reject}</button>
			<button class="btn btn--secondary" data-decision="return" data-sheet="${sheet.id}" data-tx="${tx.id}">${t.returnTx}</button>
			<span class="tx-card__actions-spacer"></span>
			<button class="btn btn--link" data-details="${sheet.id}:${tx.id}">${t.openDetails}</button>
			<button class="btn btn--primary" data-decision="approve" data-sheet="${sheet.id}" data-tx="${tx.id}">${I("check")} ${t.approve}</button>
		</div>`;

	return `<article class="tx-card${decided ? " is-decided" : ""}">
		<div class="tx-card__main">
			<span class="tx-card__icon">${I(tx.icon)}</span>
			<div class="tx-card__title-block">
				<h4 class="tx-card__title">${tx.title}</h4>
				<p class="tx-card__desc">${tx.desc}</p>
				<p class="tx-card__meta">
					<span class="tx-card__meta-item"><span class="tx-card__meta-id">${tx.id}</span></span>
					<span class="tx-card__meta-item">${tx.meta.merchant}</span>
					<span class="tx-card__meta-item">${tx.meta.paid}</span>
					<span class="tx-card__meta-item">${tx.meta.method}</span>
					<span class="tx-card__meta-item">${tx.meta.category}</span>
				</p>
			</div>
		</div>
		<div class="tx-card__side">
			<div class="tx-card__amount">${money(tx.currency, tx.amount)}</div>
			${pill(tx.status)}
		</div>
		<div class="tx-card__docs">
			<span class="docs-label">${I(iconFor(docs[0].kind))} ${t.docsLabel} <span class="docs-count">${docs.length}</span></span>
			<div class="docs-row">${thumbs}${moreTile}</div>
		</div>
		${noteBlock}
	</article>`;
}

function sheetBody(sheet) {
	const t = COPY[state.lang];
	const scopeName = state.lang === "fa" ? "مرکز هزینه شمال" : "Cost Center North";
	return `<div class="sheet__body">
		<div class="sheet-body__head">
			<div>
				<p class="sheet-body__eyebrow">${t.bodyEyebrow}</p>
				<h3 class="sheet-body__title">${sheet.title} <span class="sheet-body__title-id">${sheet.id}</span></h3>
				<p class="sheet-body__meta">
					<span>${t.purchaserLabel}: <strong>${sheet.purchasedBy}</strong></span>
					<span class="sheet-body__scope">${I("route")} ${scopeName}</span>
					<span>${sheet.submitted}</span>
				</p>
			</div>
			<button class="sheet-body__collapse" data-collapse="${sheet.id}">${t.collapseLink}</button>
		</div>
		<div class="tx-list">${sheet.txs.map((tx) => txCard(sheet, tx)).join("")}</div>
		<p class="sheet-body__foot"><span><strong>${t.footTitle}</strong> ${t.footText}</span></p>
	</div>`;
}

function sheetRow(sheet, expanded) {
	const t = COPY[state.lang];
	const p = sheet.purchaser;
	return `<li class="sheet" data-sheet-row="${sheet.id}">
		<button class="sheet__summary" aria-expanded="${expanded}" data-sheet="${sheet.id}"
			aria-label="${expanded ? t.chevronClose : t.chevronOpen} ${sheet.title}">
			<span class="sheet__identity">
				<span class="sheet__icon">${I("sheetIcon")}</span>
				<span class="sheet__title-block">
					<span class="sheet__title">${sheet.title}</span>
					<span class="sheet__meta"><span class="sheet__id">${sheet.id}</span><span class="sheet__meta-dot" aria-hidden="true"></span><span>${sheet.aggregate}</span></span>
				</span>
			</span>
			<span class="sheet__purchaser">
				<span class="avatar avatar--sm avatar--soft" aria-hidden="true">${p.initials}</span>
				<span class="sheet__purchaser-text"><span class="sheet__purchaser-name">${p.name}</span><span class="sheet__purchaser-role">${p.role}</span></span>
			</span>
			<span class="sheet__stats">
				<span class="sheet__stat"><span class="sheet__stat-label">${t.statTxLabel}</span><span class="sheet__stat-value">${sheet.txIds.length}</span></span>
				<span class="sheet__stat sheet__stat--numeric"><span class="sheet__stat-label">${t.statTotalLabel}</span><span class="sheet__stat-value sheet__stat-value--money" style="display:inline-flex;gap:4px">${money(sheet.currency, sheet.txTotal)}</span></span>
			</span>
			<span class="sheet__status">${pill(sheet.status)}</span>
			<span class="sheet__submitted">${sheet.submitted}</span>
			<span class="sheet__chevron" aria-hidden="true" style="${expanded ? "transform:rotate(180deg)" : ""}">${I("chev")}</span>
		</button>
		${expanded ? sheetBody(sheet) : ""}
	</li>`;
}

function faNum(n) {
	return state.lang === "fa" ? String(n).replace(/\d/g, (d) => "۰۱۲۳۴۵۶۷۸۹"[Number(d)]) : String(n);
}

function pendingCounts(data) {
	const pending = data.sheets.reduce((n, s) => n + s.txs.filter((x) => x.status === "awaiting").length, 0);
	const sheets = data.sheets.filter((s) => s.txs.some((x) => x.status === "awaiting")).length;
	return { pending, sheets };
}

function queueCard(data) {
	const t = COPY[state.lang];
	const { pending, sheets } = pendingCounts(data);
	const queueCount = state.lang === "fa"
		? `${faNum(data.sheets.length)} برگه در صف · ${faNum(pending)} تراکنش برای تصمیم`
		: `${data.sheets.length} sheets in queue · ${pending} transactions to decide`;
	const expandedSheet = state.view && state.view.sheet ? data.sheets.find((s) => s.id === state.view.sheet) : null;
	const list = data.sheets
		.map((s) => sheetRow(s, !!expandedSheet && s.id === expandedSheet.id))
		.join("");
	return `<section class="queue-card">
		<div class="queue-card__head">
			<div>
				<h2 class="queue-card__title">${t.queueTitle}</h2>
				<p class="queue-card__desc">${t.queueDesc}</p>
			</div>
			<span class="queue-card__count">${queueCount}</span>
		</div>
		<p class="availability-note"><strong>ℹ︎</strong> ${t.availabilityNote}</p>
		<div class="queue-toolbar">
			<label class="search">
				<span class="search__icon" aria-hidden="true">${I("search")}</span>
				<span class="sr-only">${t.searchLabel}</span>
				<input class="search__input" type="search" placeholder="${t.searchPlaceholder}">
			</label>
			<nav class="tabs" aria-label="${t.colStatus}">
				${t.tabs.map(([label, n], i) => `<button class="tab${i === 0 ? " is-active" : ""}">${label}<span class="tab__count">${faNum(n)}</span></button>`).join("")}
			</nav>
			<label class="sort"><span class="sort__label">${t.sortLabel}</span>
				<select class="select">${t.sortOptions.map((o) => `<option>${o}</option>`).join("")}</select>
			</label>
		</div>
		<div class="sheet-list__head" aria-hidden="true">
			<span>${t.colSheet}</span>
			<span>${t.colPurchaser}</span>
			<span>${t.colTx}</span>
			<span class="sheet-list__head--numeric">${t.colTotal}</span>
			<span>${t.colStatus}</span>
			<span>${t.colSubmitted}</span>
			<span></span>
		</div>
		<ul class="sheet-list">${list}</ul>
	</section>`;
}

function pageShell(data) {
	const t = COPY[state.lang];
	const m = data.manager;
	const { pending, sheets } = pendingCounts(data);
	const stats = [
		{ label: t.statSheets, value: faNum(sheets), hint: t.statSheetsHint, icon: "sheetIcon", cls: "" },
		{ label: t.statTx, value: faNum(pending), hint: t.statTxHint, icon: "clock", iconCls: "stat__icon--amber" },
	];
	return `
	<header class="nc-header">
		<div class="nc-header__left">
			<div class="brand"><span class="brand__mark">PC</span><span class="brand__name">Petty cash <small>Cost center review</small></span></div>
			<span class="nc-header__crumbs">${t.crumbs.map((c) => `<span>${c}</span>`).join('<span aria-hidden="true">›</span>')}</span>
		</div>
		<div class="nc-header__right">
			<span class="sim-pill" title="${t.simPill}">${t.simPill}</span>
			<button class="icon-btn" aria-label="Open nextcloud apps">${I("search")}</button>
			<span class="avatar" aria-hidden="true">${m.initials}</span>
		</div>
	</header>
	<div class="app-body">
		<nav class="sidebar" aria-label="Primary">
			<div class="sidebar__section">${t.nav.map(navItem).join("")}</div>
			<div class="sidebar__footer">
				<span class="avatar avatar--sm">${m.initials}</span>
				<span class="user-line__text"><strong>${m.name}</strong><span>${m.role}</span></span>
			</div>
		</nav>
		<main class="content">
			<div class="page-head">
				<div class="page-head__main">
					<p class="eyebrow">${t.pageEyebrow}</p>
					<h1 class="page-title">${t.pageTitle}</h1>
					<p class="page-subtitle">${t.pageSubtitle}</p>
				</div>
				<div class="page-head__side"><span class="role-badge">${t.roleBadge}</span></div>
			</div>
			<div class="scope-banner">
				<span class="scope-banner__icon" aria-hidden="true">${I("route")}</span>
				<div class="scope-banner__body">
					<p class="scope-banner__title">${t.bannerTitle}</p>
					<p class="scope-banner__text">${t.bannerText}</p>
				</div>
				<a class="scope-banner__link" href="#" onclick="return false">${t.bannerLink}</a>
			</div>
			<div class="summary-grid">${stats.map((s, i) => statCard({ ...s, iconCls: s.iconCls || (i === 2 ? "" : "") })).join("")}</div>
			${queueCard(data)}
		</main>
	</div>`;
}

/* ---------------------------- overlays ------------------------------------- */

function docPreviewOverlay(data) {
	const t = COPY[state.lang];
	const { sheet, tx } = state.view;
	const index = Math.max(0, data.sheets.findIndex((s) => s.id === sheet));
	const sh = data.sheets[index];
	const tIndex = sh.txs.findIndex((x) => x.id === tx);
	const txx = sh.txs[tIndex];
	const docs = txx.docs;
	const docById = state.view.docId || docs[0].id;
	let dIndex = docs.findIndex((d) => d.id === docById);
	if (dIndex < 0) dIndex = 0;
	const doc = docs[dIndex];
	const unsupported = !doc.art;
	return `<div class="overlay" role="dialog" aria-modal="true" aria-label="${t.previewEyebrow}: ${doc.name}">
		<div class="overlay__scrim" data-close="doc"></div>
		<div class="modal">
			<header class="modal__header">
				<div class="modal__titles">
					<p class="modal__eyebrow">${t.previewEyebrow}</p>
					<h2 class="modal__title">${doc.name}</h2>
					<p class="modal__meta"><span class="tx-card__meta-id">${txx.id}</span><span>${txx.title}</span><bdi>${sh.id}</bdi></p>
				</div>
				<button class="modal__close" data-close="doc" aria-label="${t.previewClose}">${I("close")}</button>
			</header>
			${unsupported
				? `<div class="modal__body"><div class="file-fallback">
					<span class="file-fallback__icon">${I(iconFor(doc.kind))}</span>
					<p class="file-fallback__name">${doc.name}</p>
					<p class="file-fallback__meta">${t.downloadMeta}</p>
					<p class="file-fallback__note">${t.unsupportedText}</p>
				</div></div>`
				: `<div class="modal__body"><div class="doc-preview"><img class="doc-preview__art" src="${doc.art}" alt=""></div></div>`}
			<footer class="modal__footer">
				<div class="doc-preview__nav">
					<button class="btn btn--secondary btn--sm" data-docnav="-1" ${dIndex === 0 ? "disabled" : ""}>${I("back")} ${t.previewNavPrev}</button>
					<span class="doc-preview__counter">${dIndex + 1} / ${docs.length}</span>
					<button class="btn btn--secondary btn--sm" data-docnav="1" ${dIndex === docs.length - 1 ? "disabled" : ""}>${t.previewNavNext} ${I("chevR")}</button>
				</div>
				<div class="modal__footer-group">
					<button class="btn btn--ghost" data-close="doc">${t.previewClose}</button>
					<button class="btn btn--primary" data-download>${I("download")} ${t.previewDownload}</button>
				</div>
			</footer>
		</div>
	</div>`;
}

function detailsOverlay(data) {
	const t = COPY[state.lang];
	const { sheet, tx } = state.view;
	const sh = data.sheets.find((s) => s.id === sheet);
	const txx = sh.txs.find((x) => x.id === tx);
	const decided = txx.status === "approved" || txx.status === "returned" || txx.status === "rejected";
	const decideBlock = decided
		? ""
		: `<span class="drawer__decide-label">${t.decideLabel}</span>` +
			`<button class="btn btn--danger" data-decision="reject" data-sheet="${sh.id}" data-tx="${txx.id}">${I("close")} ${t.reject}</button>` +
			`<button class="btn btn--secondary" data-decision="return" data-sheet="${sh.id}" data-tx="${txx.id}">${I("route")} ${t.returnTx}</button>` +
			`<button class="btn btn--primary" data-decision="approve" data-sheet="${sh.id}" data-tx="${txx.id}">${I("check")} ${t.approve}</button>`;
	const items = [
		[t.detailSheet, `<span class="sheet__id">${sh.id}</span>`],
		[t.detailPurchaser, `${sh.purchasedBy} · ${sh.purchaser.role}`],
		[t.detailMerchant, txx.meta.merchant],
		[t.detailPaid, txx.meta.paid],
		[t.detailMethod, txx.meta.method],
		[t.detailCategory, txx.meta.category],
		[
			t.detailCostCenter,
			state.lang === "fa" ? "مرکز هزینه شمال" : "Cost Center North",
		],
		[t.detailStatus, pill(txx.status)],
	];
	const scopeName = state.lang === "fa" ? "مرکز هزینه شمال" : "Cost Center North";
	const grid = `<div class="detail-grid">${items
		.map(([k, v]) => `<div class="detail-item"><p class="detail-item__label">${k}</p><p class="detail-item__value">${v}</p></div>`)
		.join("")}</div>`;
	const docs = `<div class="detail-section"><h3 class="detail-section__title">${t.detailDocs}</h3>
		<div class="detail-docs">${txx.docs
			.map(
				(d) => `<button class="detail-doc" data-doc="${d.id}" data-sheet="${sh.id}" data-tx="${txx.id}">
					<span class="detail-doc__art">${d.art ? `<img class="doc-thumb__art" src="${d.art}" alt="">` : I(iconFor(d.kind))}</span>
					<span class="detail-doc__text"><span class="detail-doc__name">${d.name}</span><span class="detail-doc__meta">${d.ext} · 214 KB</span></span>
				</button>`
			)
			.join("")}</div></div>`;
	const history = `<div class="detail-section" hidden data-panel="history"><h3 class="detail-section__title">${t.detailHistory}</h3>
		<ul class="detail-history">${txx.history
			.map(
				([title, meta], i, arr) => `<li class="history-item">
					<span class="history-item__rail"><span class="history-item__dot"></span>${i === arr.length - 1 ? "" : '<span class="history-item__line"></span>'}</span>
					<div class="history-item__body"><p class="history-item__title">${title}</p><p class="history-item__meta">${meta}</p></div>
				</li>`
			)
			.join("")}</ul></div>`;
	return `<div class="overlay overlay--drawer" role="dialog" aria-modal="true" aria-label="${t.detailsEyebrow}: ${txx.title}">
		<div class="overlay__scrim" data-close="details"></div>
		<aside class="drawer">
			<header class="drawer__header">
				<div class="drawer__head-row">
					<div>
						<p class="modal__eyebrow">${t.detailsEyebrow}</p>
						<h2 class="drawer__title">${txx.title}</h2>
						<p class="drawer__meta"><span class="tx-card__meta-id">${txx.id}</span><span>${sh.title}</span><bdi>${sh.id}</bdi></p>
					</div>
					<button class="modal__close" data-close="details" aria-label="${t.detailsClose}">${I("close")}</button>
				</div>
				<nav class="drawer__tabs">
					<button class="drawer__tab is-active" data-dtab="about">${t.detailAbout}</button>
					<button class="drawer__tab" data-dtab="history">${t.detailHistory}</button>
				</nav>
			</header>
			<div class="drawer__body">
				<div data-panel="about">
					<div class="detail-hero">
						<div>
							<div class="detail-hero__amount">${money(txx.currency, txx.amount)}</div>
							<p class="detail-hero__caption">${t.detailAmount} · ${txx.meta.paid}</p>
						</div>
						<div class="detail-hero__side">${pill(txx.status)}<span class="detail-hero__caption">${scopeName}</span></div>
					</div>
					${grid}
					<div class="detail-section"><h3 class="detail-section__title">${t.detailAbout}</h3><p class="detail-description">${txx.description}</p></div>
					${docs}
				</div>
				${history}
				<p class="detail-note">${t.detailPreviewNote}</p>
			</div>
			<footer class="drawer__footer">
				<span class="modal__footer-note">${t.detailsNote}</span>
				<div class="modal__footer-group">
					${decideBlock}
					<button class="btn btn--secondary" data-close="details">${t.detailsClose}</button>
				</div>
			</footer>
		</aside>
	</div>`;
}

function confirmOverlay(kind, submitting, showError) {
	const t = COPY[state.lang];
	const { sheet, tx } = state.view;
	const data = DATA[state.lang];
	const sh = data.sheets.find((s) => s.id === sheet);
	const txx = sh.txs.find((x) => x.id === tx);
	const reject = kind === "reject";
	const returning = kind === "return";
	const title = reject ? t.rejectTitle : returning ? t.returnTitle : t.confirmTitle;
	const actionLabel = reject ? t.confirmReject : returning ? t.confirmReturn : t.confirmApprove;
	const actionIcon = reject ? "close" : returning ? "route" : "check";
	const warn = reject ? t.rejectWarning : returning ? t.returnWarning : t.confirmWarning;
	const fieldLabel = reject ? t.rejectLabel : returning ? t.returnLabel : t.confirmNoteLabel;
	const fieldHint = reject ? t.rejectHint : returning ? t.returnHint : t.confirmNoteHint;
	const fieldPlaceholder = reject ? t.rejectPlaceholder : returning ? t.returnPlaceholder : "";
	const errShort = reject ? t.errorShort : t.errorReturnShort;
	const spinning = submitting
		? `<span class="btn__spinner" aria-hidden="true"></span>${t.confirmSubmitting}`
		: `${I(actionIcon)} ${actionLabel}`;
	return `<div class="overlay" role="dialog" aria-modal="true" aria-label="${title}">
		<div class="overlay__scrim" data-close="confirm"></div>
		<div class="modal modal--decision">
			<header class="modal__header">
				<div class="modal__titles">
					<p class="modal__eyebrow">${t.bodyEyebrow} · ${sh.id}</p>
					<h2 class="modal__title">${title}</h2>
					<p class="modal__meta"><span class="tx-card__meta-id">${txx.id}</span><span>${txx.title}</span></p>
				</div>
				<button class="modal__close" data-close="confirm" aria-label="${t.confirmCancel}" ${submitting ? "disabled" : ""}>${I("close")}</button>
			</header>
			<div class="decision__body">
				<div class="decision__summary">
					<div>
						<p class="decision__summary-title">${txx.title}</p>
						<p class="decision__summary-meta"><bdi>${sh.id}</bdi> · <bdi>${txx.meta.paid}</bdi></p>
					</div>
					<span class="decision__summary-amount">${txx.currency} ${txx.amount}</span>
				</div>
				<p class="decision__warning${reject ? " decision__warning--danger" : returning ? " decision__warning--return" : ""}">${I("alert")} <span>${warn}</span></p>
				<div class="decision__field">
					<label class="decision__label" for="decision-note">${fieldLabel}</label>
					<span class="decision__hint">${fieldHint}</span>
					<textarea class="decision__textarea" id="decision-note" ${(reject || returning) && showError ? 'aria-invalid="true"' : ""}
						placeholder="${fieldPlaceholder}">${reject && !showError ? (state.lang === "fa" ? "دو اسم مهمان با فهرست حضور هم‌خوانی ندارد؛ رسید تفصیلی مجدداً بارگذاری شود." : "Two guest names don't match the attendance list — please re-upload the itemised receipt.") : ""}</textarea>
					${showError ? `<p class="decision__error">${I("alert")} <span><strong>${t.errorLabel}:</strong> ${errShort}</span></p>` : ""}
				</div>
			</div>
			<footer class="decision__footer">
				<span class="decision__footer-note">${t.confirmFooterNote}</span>
				<div class="modal__footer-group">
					<button class="btn btn--secondary" data-close="confirm" ${submitting ? "disabled" : ""}>${t.confirmCancel}</button>
					<button class="btn ${reject ? "btn--danger-solid" : returning ? "btn--return" : "btn--primary"}" data-confirm="${kind}" ${submitting ? 'aria-disabled="true"' : ""}>${spinning}</button>
				</div>
			</footer>
		</div>
	</div>`;
}

function toastHTML(toast) {
	const t = COPY[state.lang];
	const statusLabel = {
		approved: t.pillApproved,
		rejected: t.pillRejected,
		returned: t.pillReturned,
	}[toast.status];
	return `<div class="toast" role="status" data-toast>
		<span class="toast__icon">${I(toast.status === "approved" ? "check" : "alert")}</span>
		<span><strong>${toast.tx} · ${statusLabel}</strong> · ${t.toastText}</span>
	</div>`;
}

/* ---------------------------------------------------------------------------
 * Mount + events
 * ------------------------------------------------------------------------- */

function mount() {
	const t = COPY[state.lang];
	const data = DATA[state.lang];
	state.view = state.view || {};

	/* apply URL state focus */
	const focus = FOCUS[state.name];
	if (state.name === "expanded" && !state.view.sheet) state.view = { sheet: "SHEET-0251" };
	if (focus && !state.view.sheet) state.view = { ...focus, overlay: state.name === "doc" ? "doc" : state.name === "unsupported" ? "doc" : state.name === "details" ? "details" : state.name === "confirm" || state.name === "return" || state.name === "error" || state.name === "submitting" ? "confirm" : null };

	/* completed capture: mark decision applied once */
	if (state.name === "completed" && !state.decisionApplied) {
		state.decisionApplied = true;
		const focus2 = FOCUS.completed;
		const sh = data.sheets.find((s) => s.id === focus2.sheet);
		const txx = sh.txs.find((x) => x.id === focus2.tx);
		txx.status = "approved";
		txx.decidedNote = t.decidedNote;
		txx.decidedMeta = state.lang === "fa" ? "همین حالا" : "Just now";
		state.view = { sheet: focus2.sheet };
		state.toast = { tx: focus2.tx, status: "approved" };
		state.doneDelta = 1;
	} else {
		state.toast = params.get("toast") === "1" ? { tx: "TX-10842", status: "approved" } : null;
	}

	const overlayKinds = { doc: "doc", unsupported: "doc", details: "details", confirm: "confirm", return: "confirm", error: "confirm", submitting: "confirm" };
	state.view.overlay = state.view.overlay ?? overlayKinds[state.name] ?? null;

	const app = document.getElementById("app");
	let html = pageShell(data);
	const view = state.view;
	if (view.overlay === "doc") html += docPreviewOverlay(data);
	else if (view.overlay === "details") html += detailsOverlay(data);
	else if (view.overlay === "confirm") html += confirmOverlay(view.kind || "approve", state.name === "submitting", state.name === "error");
	app.innerHTML = html + (state.toast ? toastHTML(state.toast) : "");

	/* mobile: when a sheet expands, bring its quick-review panel into view
	   without disorienting; skip during capture so screenshots stay stable */
	if (state.device === "mobile" && !state.capture && !state.view.overlay) {
		const expId = state.view.sheet || null;
		if (expId && expId !== lastExpandedId) {
			const body = app.querySelector(".sheet__body");
			if (body) body.scrollIntoView({ behavior: "smooth", block: "start" });
		}
		lastExpandedId = expId;
	}

	/* interactions */
	app.querySelectorAll("button.sheet__summary").forEach((btn) => {
		btn.addEventListener("click", () => {
			const id = btn.getAttribute("data-sheet");
			state.view = state.view.sheet === id && !state.view.overlay ? { sheet: null } : { sheet: id };
			mount();
		});
	});
	app.querySelectorAll("[data-collapse]").forEach((btn) =>
		btn.addEventListener("click", (e) => {
			e.stopPropagation();
			state.view = { sheet: null };
			mount();
		})
	);
	app.querySelectorAll("[data-details]").forEach((btn) =>
		btn.addEventListener("click", (e) => {
			e.stopPropagation();
			const [sheet, tx] = btn.getAttribute("data-details").split(":");
			state.view = { sheet, tx, overlay: "details", dtab: "about" };
			mount();
		})
	);
	app.querySelectorAll("[data-decision]").forEach((btn) =>
		btn.addEventListener("click", (e) => {
			e.stopPropagation();
			state.view = { sheet: btn.dataset.sheet, tx: btn.dataset.tx, overlay: "confirm", kind: btn.dataset.decision };
			mount();
		})
	);
	app.querySelectorAll("[data-doc]").forEach((btn) =>
		btn.addEventListener("click", (e) => {
			e.stopPropagation();
			state.view = { sheet: btn.dataset.sheet, tx: btn.dataset.tx, overlay: "doc", docId: btn.dataset.doc };
			mount();
		})
	);
	app.querySelectorAll("[data-doc-more]").forEach((btn) =>
		btn.addEventListener("click", () => {
			const [sheet, tx] = btn.getAttribute("data-doc-more").split(":");
			state.view = { sheet, tx, overlay: "doc", docId: null };
			mount();
		})
	);
	app.querySelectorAll("[data-close]").forEach((btn) =>
		btn.addEventListener("click", () => {
			if (state.name === "submitting") return;
			state.view = state.view.sheet ? { sheet: state.view.sheet } : {};
			state.toast = false;
			mount();
		})
	);
	app.querySelectorAll("[data-docnav]").forEach((btn) =>
		btn.addEventListener("click", () => {
			const dir = parseInt(btn.getAttribute("data-docnav"), 10);
			const data2 = DATA[state.lang];
			const sh = data2.sheets.find((s) => s.id === state.view.sheet);
			const txx = sh.txs.find((x) => x.id === state.view.tx);
			let i = txx.docs.findIndex((d) => d.id === state.view.docId);
			if (i < 0) i = 0;
			const next = txx.docs[Math.min(txx.docs.length - 1, Math.max(0, i + dir))];
			state.view.docId = next.id;
			mount();
		})
	);
	app.querySelectorAll("[data-dtab]").forEach((btn) =>
		btn.addEventListener("click", () => {
			const tab = btn.getAttribute("data-dtab");
			document.querySelectorAll("[data-dtab]").forEach((b) => b.classList.toggle("is-active", b === btn));
			document.querySelectorAll("[data-panel]").forEach((p) => {
				p.hidden = p.getAttribute("data-panel") !== tab;
			});
		})
	);
	app.querySelectorAll("[data-confirm]").forEach((btn) =>
		btn.addEventListener("click", () => {
			if (state.name === "submitting") return;
			const kind = btn.getAttribute("data-confirm");
			/* the reject/return-without-note guard (design behavior) */
			if (kind === "reject" || kind === "return") {
				const note = document.getElementById("decision-note");
				if (!note.value.trim()) {
					state.name = "error";
					state.view.kind = kind;
					mount();
					return;
				}
			}
			const data2 = DATA[state.lang];
			const sh = data2.sheets.find((s) => s.id === state.view.sheet);
			const txx = sh.txs.find((x) => x.id === state.view.tx);
			txx.status = kind === "approve" ? "approved" : kind === "return" ? "returned" : "rejected";
			state.name = "completed";
			state.view = { sheet: sh.id };
			state.toast = { tx: txx.id, status: txx.status };
			state.doneDelta = (state.doneDelta || 0) + 1;
			mount();
		})
	);
	const dl = app.querySelector("[data-download]");
	if (dl) dl.addEventListener("click", () => dl.setAttribute("aria-disabled", "true"));
}

/* toolbar wiring lives in index.html; boot */
(function boot() {
	state.name = params.get("state") || "collapsed";
	if (!document.body.classList.contains("is-capture") && state.capture) document.body.classList.add("is-capture");
	const frame = document.querySelector(".app-frame");
	const t = COPY[state.lang];
	frame.setAttribute("lang", state.lang);
	frame.setAttribute("dir", t.dir);
	frame.classList.toggle("app-frame--mobile", state.device === "mobile");
	mount();
})();
