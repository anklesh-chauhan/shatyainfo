# Shatya Info Data Processing Agreement

**Effective 2 November 2026 · Version 2026-11-02**

This Data Processing Agreement ("DPA") is between **Rekha Chauhan**, proprietor of **Shatya Info** (Udyam Registration No. **UDYAM-GJ-01-0702612**) ("Provider"), and the customer under the Shatya Info Service Agreement ("Customer"). It forms part of that agreement. Its purpose is to set out how Provider handles personal data supplied to the Customer's workspace. Applicable Indian data-protection law, including provisions of the Digital Personal Data Protection Act, 2023 and its rules as they commence, controls where mandatory.

## 1. Roles and instructions

For personal data entered into the Customer's workspace ("Customer Personal Data"), Customer determines the purposes and means of processing and Provider processes the data on Customer's documented instructions. The Service Agreement, this DPA, and settings or written requests made by authorised Customer administrators are those instructions. Provider may process data as required by law after informing Customer unless prohibited by law. Provider will tell Customer if an instruction appears unlawful and may pause that instruction while the parties resolve it.

For Provider's own account registration, billing, security, support, and website records, Provider determines its own purposes and describes that processing in its Privacy Notice.

Customer is responsible for notices, permissions or other legal grounds needed for data it uploads; deciding who may access records; responding to people whose data it controls; and setting any pharma-specific retention or validation rules. Customer must not upload patient records, adverse-event case files, or GMP-critical records unless the parties first agree in writing on the additional controls, validation, retention, and responsibilities required for that use. If Customer enables optional AI briefings, it instructs Provider to send the limited fact set described in Schedule C to the selected model endpoint. Cloud model providers may process data outside India; an India-only processing requirement means the Customer should leave cloud AI disabled until a compliant provider arrangement is agreed.

## 2. Processing details

| Item | Description |
| --- | --- |
| Subject | Hosting and operating the Shatya Info workspace, backup, security, troubleshooting, and Customer-authorised support |
| Duration | During the trial and subscription, then the agreed export, retention, and deletion period |
| People | Customer users and employees; healthcare professionals; customer and supplier contacts; other people the Customer enters into field or business records |
| Data | Names, work contact details, roles, login and access records, territories, visit and activity records, sample and stock records, business documents, support communications, and other Customer-entered fields |
| Operations | Collection, storage, organisation, retrieval, display, transmission to authorised users, backup, correction, export, and deletion |
| Location | The primary application and database are hosted with DigitalOcean in India. Email delivery, payment, traffic protection, and optional cloud AI may involve processing elsewhere. |

## 3. Confidentiality and safeguards

Provider will limit access to Customer Personal Data to people who need it to provide or secure the service and are bound by confidentiality. Provider will maintain safeguards appropriate to the risk, including access controls, tenant separation, secure transport, software updates, logging, backup, recovery, and incident response. The measures built into the application are described in **Schedule A**. Provider will review those safeguards and will not materially reduce protection without notice and a lawful basis.

## 4. Security incidents

Provider will notify Customer without undue delay and no later than 48 hours after confirming a personal-data breach affecting Customer Personal Data, using the registered owner email and any incident contact the Customer has supplied. Provider's incident contact is **support@shatyainfo.com / +91 9978922922**. The notice will share facts then known, affected data and people where reasonably ascertainable, containment steps, and a contact for updates. Provider will investigate, mitigate, preserve relevant evidence, and provide reasonable cooperation so Customer can meet its own notification duties. Each party handles notifications to regulators and individuals for which it is legally responsible, unless otherwise agreed in writing.

## 5. Subprocessors

Customer authorises the subprocessors identified in **Schedule B**. Provider will impose written confidentiality, security, and data-protection obligations on each subprocessor and remains responsible to Customer for its processing. Provider will notify Customer **15 days** before adding or replacing a subprocessor that can access Customer Personal Data. Customer may object on reasonable data-protection grounds; the parties will try to resolve the concern, including by an alternative arrangement or termination of affected services if no reasonable solution exists.

## 6. Assistance, requests, and audit

Taking account of the processing available to it, Provider will reasonably assist Customer with requests to access, correct, export, or erase Customer Personal Data, and with privacy-impact, security, and breach inquiries required by applicable law. Provider will not answer a data subject request about Customer-controlled data on Customer's behalf unless authorised or legally required, and will forward it to Customer where practicable.

On reasonable notice, Provider will supply relevant security information and allow a proportionate audit by Customer or an independent auditor subject to confidentiality, security, and protection of other tenants. The Customer pays its own auditor and Provider's reasonable, pre-agreed costs of a requested audit. Scope and timing must be agreed in advance; access to other tenants' data, secrets, or production credentials is excluded. Regulator rights under law are unaffected.

## 7. Return and deletion

For 30 days after service ends, the Customer may email support to request a support-assisted export of Customer Personal Data in CSV or Excel format. On the Customer's documented deletion request or after that export window, Provider will delete or return active copies within 7 days and backup copies within a further 15 days, unless retention is legally required. Provider will restrict retained data to that legal purpose and confirm deletion on request. Cancelling billing alone does not instantly delete workspace records.

## 8. Transfers and changes in law

Provider describes its service providers in Schedule B. Core application and database hosting is in India; this does not mean that email, Cloudflare, Razorpay, or an enabled cloud AI provider is India-only. Provider will transfer or permit access outside India only as permitted by applicable law and the agreed processing instructions. The parties will amend this DPA where a change in applicable law or a customer's regulated use requires additional terms.

## Schedule A — security and service measures

The application has individual accounts, role-based permissions, hashed passwords, encryption for selected stored credentials, and a separate database for each company workspace. It records selected security and AI events. We use access control, backups, software updates, and an incident response process to protect the hosted service. Ask **support@shatyainfo.com** for current operational details, including backup and recovery arrangements, before using the service for a regulated recordkeeping purpose. The service has no claimed ISO certification or GxP validation.

## Schedule B — service providers

| Provider or service | Purpose | Data involved | Location |
| --- | --- | --- | --- |
| DigitalOcean | Core application and database hosting | Workspace data | India for primary hosting |
| Cloudflare | DNS and, when enabled, registration CAPTCHA | Domain, IP and request or challenge metadata | May involve processing outside India |
| Configured email delivery service | Verification, trial, billing and support messages | Recipient address and message content | Depends on the mail service configured at the time |
| OpenAI or Anthropic, only if enabled by Customer | Optional AI fact selection | The limited facts in Schedule C | May involve processing outside India |
| Customer-selected private Ollama endpoint, only if enabled | Optional AI fact selection | The limited facts in Schedule C | Set by the Customer's endpoint |

Razorpay handles checkout and payment information under its own terms. We receive payment identifiers and status for reconciliation. Customers may ask support for the currently configured email and backup provider details.

## Schedule C — optional AI briefing data flow

When AI briefing is enabled and configured for a tenant, the application sends a JSON map of fact IDs and text for up to three previous completed visits and up to five open follow-ups associated with the visit. Visit facts may contain the visit date, outcome label, and free-text remarks. Follow-up facts may contain the due date, overdue status, and free-text interaction. Free text can contain personal or sensitive information entered by Customer users. The account name, sample suggestion payload, full visit record, and user email are not intentionally placed in the model request. The model is asked to return only one to four existing fact IDs; the application maps those IDs back to locally held facts. Model selections are cached in the application for five minutes; application logs record provider, model, status, usage, and latency, not the fact text.

Provider options are OpenAI API, Anthropic API, or a configured private Ollama endpoint. OpenAI requests set `store: false`; OpenAI's published API controls say API data is not used for training by default unless opted in, but abuse-monitoring content may be kept for up to 30 days. Anthropic's published API policy says inputs and outputs are ordinarily deleted within 30 days, subject to stated exceptions and any separate agreement, and are not used to train models by default unless the customer opts in or submits feedback. Ollama retention depends on the Customer-controlled endpoint. These are provider policies, not a guarantee by Shatya Info, and their current terms should be checked before enabling cloud AI. [OpenAI API data controls](https://developers.openai.com/api/docs/guides/your-data); [Anthropic API data retention](https://privacy.anthropic.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data); [Anthropic training policy](https://privacy.claude.com/en/articles/7996868-is-my-data-used-for-model-training).

---

Questions or requests under this DPA can be sent to **support@shatyainfo.com**.
