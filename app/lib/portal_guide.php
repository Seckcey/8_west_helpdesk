<?php
/** Reviewed customer help selected from authenticated server context, never request parameters. */
declare(strict_types=1);

/** Presentation only: callers supply an authenticated context. This grants no action permission. */
function portal_guide_audience(?array $context): string
{
    $identity = $context['identity'] ?? [];
    if (isset($identity['customer_access'])
        || isset($context['desktop_companion_pair']) || isset($_SESSION['desktop_companion_session'])) return 'legacy';
    return match ($identity['role'] ?? '') {
        'client_staff' => 'employee',
        'client_viewer' => 'viewer',
        'client_owner', 'client_admin' => 'owner',
        default => 'legacy',
    };
}

/** No context preserves historical source IDs for the stored-draft guidance parser. */
function portal_guide_articles(?array $context = null): array
{
    $legacy = portal_guide_legacy_articles();
    $audience = portal_guide_audience($context);
    if ($audience === 'legacy') return $legacy;
    $pilot = portal_guide_pilot_articles();
    $ids = match ($audience) {
        'employee' => ['sign-in', 'assigned-computers', 'requests', 'updates', 'private-computer-link'],
        'viewer' => ['assigned-computers', 'updates', 'private-computer-link'],
        'owner' => ['requests', 'updates', 'private-computer-link', 'give-computer-access'],
    };
    $articles = array_intersect_key($pilot, array_flip($ids));
    // Preserve all historical anchors/material, replacing the two overlapping request entries.
    return $articles + $legacy;
}

/** Same audience/order as the page, with references rather than expanded bodies in model context. */
function portal_guide_westy_articles(?array $context = null): array
{
    $articles = portal_guide_articles($context);
    $legacy = portal_guide_legacy_articles();
    foreach ($articles as $id => &$article) {
        if (!isset($article['blocks'])) continue;
        $article = [
            'title' => $article['title'], 'audience' => $article['audience'],
            'application' => $article['application'], 'href' => '/portal/guide.php#'.$id,
        ];
        // Keep Westy's existing concise request guidance without duplicating the full new articles.
        if (isset($legacy[$id])) $article['text'] = $legacy[$id]['text'];
    }
    unset($article);
    return $articles;
}

/** Only bold labels and inline code from the approved text become markup; all text is escaped first. */
function portal_guide_inline(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return preg_replace_callback('/\*\*([^*]+)\*\*|`([^`]+)`/u',
        static fn(array $m): string => $m[1] !== '' ? '<strong>'.$m[1].'</strong>' : '<code>'.$m[2].'</code>', $escaped);
}

function portal_guide_article_html(array $article): string
{
    if (!isset($article['blocks'])) return '<p>'.portal_guide_inline($article['text']).'</p>';
    $html = '';
    foreach ($article['blocks'] as $block) {
        if ($block['type'] === 'steps') {
            $html .= '<ol>';
            foreach ($block['items'] as $item) $html .= '<li>'.portal_guide_inline($item).'</li>';
            $html .= '</ol>';
        } else $html .= '<p>'.portal_guide_inline($block['text']).'</p>';
    }
    return $html;
}

function portal_guide_legacy_articles(): array
{
    return [
        'devices' => ['title'=>'Check a computer', 'text'=>'Your devices shows the computers your current access permits. Client owners/admins can use their client devices, and client staff can operate devices assigned to them; viewers are read-only. When Computer checks is available, an eligible person can explicitly authorize a Windows health check of memory, system-drive space and the print service. A fresh stopped print-service result can offer a specific repair for review. Read the impact and approve that exact repair on the Computer checks page. Chat never grants approval. A repair is reported as service verified only after two separate checks; the person must still try printing. An unknown result needs support review, not repeated repair attempts. Adding a computer does not order antivirus or create a charge.'],
        'requests' => ['title' => 'Open a support request', 'text' => 'Open Support Requests and choose the action to start a request. Describe what happened, what work is affected and what you have tried. Client owners, admins and employees can send requests. Viewers have read-only access. A request is visible to its requester, client owners and admins, and the support team. Keep passwords, verification codes and secret keys out of the request.'],
        'updates' => ['title' => 'Follow a request', 'text' => 'Your support requests shows requests you opened. Client owners and admins can see client-wide requests, including older unattributed requests. Open a request to read customer-visible messages. Waiting for your reply means the support team needs an update. Replying returns it to the open queue. Resolved requests stay readable; open a new request if you need more help. Internal notes and merged histories are excluded.'],
        'response' => ['title' => 'Understand response goals', 'text' => 'A request detail may show a first-response target or when the first response was sent. A first-response target is not a resolution estimate or a promise of support hours. This portal does not establish coverage, service activation or response guarantees.'],
        'summaries' => ['title' => 'Read service summaries', 'text' => 'Client owners and admins can find verified weekly service summaries for their client under Support Requests. A new business may have no summaries yet. Service summaries cover approved operational time.'],
        'privacy' => ['title' => 'Westy and your privacy', 'text' => 'Your Westy conversation is private to your signed-in identity. On an authorized connected computer, Westy can troubleshoot, run requested commands, work with files and control supported desktop/browser tools within your current account and device access. Computer tool permissions lets you choose Allow or Deny; Allow is the default and explicit Deny choices are honored. Windows elevation remains separate. Legacy Computer checks can also offer a specific repair with its own exact approval control. Visible operation receipts distinguish queued work from completed results. Stop reply stops generation; already dispatched device work keeps its own status. Only request text you review and send becomes a support record shared with you, client owners/admins and the support team. Private chat does not grant help-desk ticket search, technician messaging, suite administration or payment authority. Open Support Requests to reach the support team.'],
    ];
}

/** Approved customer text only. Editorial/source/publication metadata stays out of this catalog. */
function portal_guide_pilot_articles(): array
{
    return [
        'sign-in' => [
            'title' => 'Sign in to Safeharbor',
            'audience' => 'Employee',
            'application' => 'Safeharbor browser',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => '**Purpose and prerequisites.** Use your own 8 West ID account to open your business’s Safeharbor support portal. This article is for employees signing in through a browser. Your business owner or administrator needs to have arranged your employee account.',
                ],
                [
                    'type' => 'steps',
                    'items' => [
                        'Open the Safeharbor customer portal.',
                        'Sign in through 8 West ID and complete any verification it asks for. If you already have an active sign-in, the portal may open directly.',
                        'Under **Account**, check **Selected client** to confirm the business.',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'text' => '**Expected result.** Your employee portal opens in Westy. Use **Your devices** to find your computers, **Support Requests** to contact the team, and **My settings** for your account preferences.',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '**If you need help.** If a retry page appears, choose **Continue with 8 West ID**. If the account or business is wrong, contact your business owner or administrator through your usual support channel. Tell them the displayed error and which business you expected. If you arrived through a private computer-access link, finish signing in and then reopen the original link.',
                ],
            ],
        ],
        'assigned-computers' => [
            'title' => 'Find your assigned computer',
            'audience' => 'Employee',
            'application' => 'Safeharbor browser',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => '**Purpose and prerequisites.** Find a computer assigned to your account and read its reported status. Sign in to the correct business. Employees and read-only viewers can use these steps.',
                ],
                [
                    'type' => 'steps',
                    'items' => [
                        'Open **Your devices**.',
                        'Find the computer’s name under **Connected computers**. If the list continues, choose **Next computers**.',
                        'Read the connection status and **Last check-in**, when shown. Choose **Refresh status** to refresh the list.',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'text' => '**Expected result.** The computer’s card shows its name, platform and reported connection status. The actions available to you follow your account’s permissions. Viewers have read-only access.',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '**If you need help.** If the page cannot retrieve your computers, try refreshing shortly. If the list loads but your computer is missing, check **Selected client** under **Account** and ask your business owner or administrator to review your computer access. If they sent you a private link, follow **Use a private computer-access link** below. When asking for support, include the computer’s name and the problem shown on the page.',
                ],
            ],
        ],
        'requests' => [
            'title' => 'Open a support request',
            'audience' => 'Employee',
            'application' => 'Safeharbor browser',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => '**Purpose and prerequisites.** Send the support team a description of your problem. Sign in with an employee account that can create requests. Client owners and administrators can follow the same steps in the customer portal.',
                ],
                [
                    'type' => 'steps',
                    'items' => [
                        'Open **Support Requests**.',
                        'Choose **New support request**.',
                        'Fill in **Short summary**, **How urgent is it?**, and **What happened?** Describe what you were trying to do, what happened, who is affected and what you have already tried. Choose **Urgent** when the business cannot operate, or **High** when important work is blocked.',
                        'Review your message and choose **Send request**.',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'text' => '**Expected result.** The saved request opens with its number and the message **Your support request was sent.** The request is visible to you, your client owners and administrators, and the support team. Keep passwords, verification codes and private computer-access links out of the message.',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '**If you need help.** If Safeharbor cannot confirm that the request was saved, check **Support Requests** before trying again. If your role is Viewer, ask your business owner or administrator to submit the request. For urgent help, use your business’s agreed urgent contact route if it has one.',
                ],
            ],
        ],
        'updates' => [
            'title' => 'Follow a support request',
            'audience' => 'Employee',
            'application' => 'Safeharbor browser',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => '**Purpose and prerequisites.** Read progress on a request or answer the support team. Sign in to the business where the request was opened. Viewers can read requests available to their accounts.',
                ],
                [
                    'type' => 'steps',
                    'items' => [
                        'Open **Support Requests** and select the request by its number or subject.',
                        'Read **Conversation** and check **Status**. **Waiting for your reply** means the support team needs an update.',
                        'If the reply form is available, enter your update and choose **Send reply**.',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'text' => '**Expected result.** Your saved update appears in the conversation. A reply to a request that was **Waiting for your reply** returns it to **Open**. **Response goal** refers to the first response.',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '**If you need help.** Employees and viewers see requests recorded under their accounts. Client owners and administrators can see their client’s requests. Resolved requests stay readable. If you still need help with a resolved request, return to **Support Requests** and choose **New support request**. A Viewer can ask their business owner or administrator to submit it. If Safeharbor cannot confirm that a reply was saved, read the conversation before sending it again.',
                ],
            ],
        ],
        'private-computer-link' => [
            'title' => 'Use a private computer-access link',
            'audience' => 'Employee',
            'application' => 'Safeharbor browser',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => '**Purpose and prerequisites.** Get access to the computer your business owner or administrator chose for you. You need your own employee or read-only account in the same business and the original private computer-access link they supplied.',
                ],
                [
                    'type' => 'steps',
                    'items' => [
                        'Open the private link.',
                        'If asked to sign in, use your own account. After signing in, reopen the original private link.',
                        'On **Computer access**, check the business named under **Use your assigned computer**. Choose **Use this computer**.',
                        'When you see **This computer is now available in Your devices**, choose **Open Your devices**.',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'text' => '**Expected result.** The computer appears in your assigned list. Your existing account permissions apply, including read-only access. An accepted assignment remains active until it is revoked.',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '**If you need help.** For a valid link containing `#token=`, the page fills the access code automatically and clears it from the address bar when JavaScript is available. If **Private access code** is visible, copy the code after `#token=` from your original private link and paste it into that field. Then choose **Use this computer**.',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'If the link is unavailable, it may have expired, been revoked or been claimed by another employee. Ask your business owner for help. If the result is uncertain, check **Your devices** before repeating the action. Keep the private link and code out of support tickets and screenshots.',
                ],
            ],
        ],
        'give-computer-access' => [
            'title' => 'Give an employee access to one computer',
            'audience' => 'Client Owner/Admin',
            'application' => 'Safeharbor browser',
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => '**Purpose and prerequisites.** Give the intended employee access to an existing computer in your business. This article is for a Client Owner or Client Administrator. Sign in with the required access, confirm the business, and make sure the employee has their own account.',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'If the employee needs an account, an authorized Client Owner/Admin first completes the current People setup in 8 West ID. The **Invite a member in 8 West ID** shortcut is available to eligible client owners and administrators signing in directly to their business account. 8 West ID checks invitation authority. Follow the account-setup instructions verified by the ID owner.',
                ],
                [
                    'type' => 'steps',
                    'items' => [
                        'Open **Your devices** and find the intended computer.',
                        'Choose **Computer access** on its card.',
                        'Under **Give a member access**, choose **Create member access link**.',
                        'Copy the displayed **Private access link** and share it privately with the intended employee. An unused link expires after 48 hours. The first eligible employee signed in to the same business who uses it receives access.',
                        'Ask the employee to follow **Use a private computer-access link** above. Check **People and access links** for **Waiting for a member** or **Has access**.',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'text' => '**Expected result.** The new link waits for a member to accept it. After successful acceptance, the access list shows the employee with **Has access**. Setting up the employee’s account and sharing computer access are separate steps.',
                ],
                [
                    'type' => 'paragraph',
                    'text' => '**If you need help.** Share the computer-access link manually. If creation returns an uncertain result or says the link was already created, check **People and access links** before creating another. If the wrong person received or claimed the link, choose **Revoke access** on the relevant record and review the result before arranging a replacement. Revocation prevents further authorization. Work already delivered to the computer may still need support review.',
                ],
            ],
        ],
    ];
}
