<?php
/** Reviewed customer-only help. No ticket, staff, device or billing context. */
declare(strict_types=1);

function portal_guide_articles(): array
{
    return [
        'devices' => ['title'=>'Check a computer', 'text'=>'Your devices shows computers connected to your business. When Computer checks is available, a business owner or admin can explicitly authorize a Windows health check of memory, system-drive space and the print service. A fresh stopped print-service result can offer a specific repair for review. Read the impact and approve that exact repair on the Computer checks page. Chat never grants approval. A repair is reported as service verified only after two separate checks; the person must still try printing. An unknown result needs support review, not repeated repair attempts. Adding a computer does not order antivirus or create a charge.'],
        'requests' => ['title' => 'Open a support request', 'text' => 'Use Write a request to describe what happened, what work is affected and what you have tried. Customer owners, admins and staff can send requests. Viewers can read requests but cannot send or reply. A request is visible to your business and the support team. Never include passwords, verification codes or secret keys.'],
        'updates' => ['title' => 'Follow a request', 'text' => 'Business support requests shows the recent requests for your business. Open a request to read customer-visible messages. Waiting for your reply means the support team needs an update. Replying returns it to the open queue. Resolved requests stay readable; open a new request if you need more help. Internal notes and merged histories are excluded.'],
        'response' => ['title' => 'Understand response goals', 'text' => 'A request detail may show a first-response target or when the first response was sent. A first-response target is not a resolution estimate or a promise of support hours. This portal does not establish coverage, service activation or response guarantees.'],
        'summaries' => ['title' => 'Read service summaries', 'text' => 'Service summaries shows verified weekly archives when they exist for your business. A new business may have no summaries yet. Approved operational time in a summary is not an invoice or payment status.'],
        'privacy' => ['title' => 'Westy and your privacy', 'text' => 'Westy can explain this portal and help draft a request. Your Westy conversation is private to your signed-in identity. Only the request text you review and send becomes a support record shared with your business and the support team. Westy cannot read tickets, contact a technician, send email, change access, control devices, take payments or perform repairs. Use Contact support to reach the direct request form.'],
    ];
}
