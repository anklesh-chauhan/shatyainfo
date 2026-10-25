<?php

return [

    'job_role_profiles' => [
        'sales_head' => 'sales_user',
        'head_of_sales' => 'sales_user',
        'regional_sales_manager' => 'sales_user',
        'area_sales_manager' => 'sales_user',
        'sales_manager' => 'sales_user',
        'sales_representative' => 'sales_user',
        'medical_representative' => 'sales_user',
        'senior_sales_executive' => 'sales_user',
        'sales_executive' => 'sales_user',
        'crm_support_lead' => 'sales_user',
        'crm_support_executive' => 'sales_user',
        'hr_manager' => 'hr_user',
        'finance_manager' => 'accounts_user',
        'accountant' => 'accounts_user',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Levels
    |--------------------------------------------------------------------------
    | F = Full (view, create, update, delete)
    | C = Create + View + Update
    | R = Read-only
    */

    'roles' => [

        /* ================= ADMIN ================= */
        'super_admin' => [
            '*' => 'F', // Full access to everything
        ],

        /* ================= SALES ================= */
        'sales_admin' => [
            'LeadResource' => 'F',
            'LeadActivityResource' => 'F',
            'DealResource' => 'F',
            'QuoteResource' => 'F',
            'SalesOrderResource' => 'F',
            'SalesInvoiceResource' => 'R',
            'CustomerPriceResource' => 'C',
            'SalesDcrResource' => 'F',
            'SalesTourPlanResource' => 'F',
            'VisitResource' => 'F',
            'FollowUpResource' => 'F',
            'ContactDetailResource' => 'C',
            'ItemMasterResource' => 'R',
            'PurchaseOrderResource' => 'C',
            'GoodsReceiptNoteResource' => 'C',
            'DeliveryChallanResource' => 'C',
            'SampleRequestResource' => 'C',
            'SampleIssueResource' => 'C',
            'PromotionalSchemeResource' => 'F',
            'MarketingCampaignResource' => 'F',
            'InventoryAdjustmentResource' => 'C',
            'InventoryTransferResource' => 'C',
            'InventoryStockResource' => 'R',
            'InventoryMovementResource' => 'R',
        ],

        'sales_user' => [
            'PatchResource' => 'C',
            'AccountMasterResource' => 'C',
            'SalesTourPlanResource' => 'C',
            'SalesDcrResource' => 'C',
            'VisitResource' => 'C',
            'FollowUpResource' => 'C',
            'LeaveApplicationResource' => 'C',
            'LeadResource' => 'C',
            'QuoteResource' => 'C',
            'SalesOrderResource' => 'C',
            'SalesInvoiceResource' => 'R',
            'SampleRequestResource' => 'C',
            'SampleIssueResource' => 'R',
        ],

        /* ================= HR ================= */
        'hr_admin' => [
            'HrChangeRequestResource' => 'C',
            'LeaveCancellationResource' => 'R',
            'LeaveApplicationResource' => 'C',
            'LeaveAdjustmentResource' => 'C',
            'LeaveEncashmentResource' => 'C',
            'EmployeeResource' => 'F',
            'EmpDepartmentResource' => 'F',
            'EmpGradeResource' => 'F',
            'EmpJobTitleResource' => 'F',
            'EmployeeAttendanceResource' => 'F',
            'EmployeeAttendanceStatusResource' => 'F',
            'DailyAttendanceResource' => 'F',
            'ShiftMasterResource' => 'F',
        ],

        'hr_user' => [
            'HrChangeRequestResource' => 'C',
            'LeaveCancellationResource' => 'R',
            'EmployeeResource' => 'C',
            'DailyAttendanceResource' => 'C',
        ],

        /* ================= FINANCE ================= */
        'finance_admin' => [
            'PaymentMethodResource' => 'F',
            'PaymentTermResource' => 'F',
            'SalesInvoiceResource' => 'F',
            'SalesDcrExpenseResource' => 'F',
            'ExpenseTypeResource' => 'F',
            'ExpenseConfigurationResource' => 'F',
            'TaxResource' => 'F',
            'PurchaseOrderResource' => 'F',
            'GoodsReceiptNoteResource' => 'F',
            'DeliveryChallanResource' => 'F',
            'SampleRequestResource' => 'F',
            'SampleIssueResource' => 'F',
            'InventoryAdjustmentResource' => 'F',
            'InventoryTransferResource' => 'F',
            'InventoryStockResource' => 'F',
            'InventoryMovementResource' => 'F',
            'ItemMasterResource' => 'R',
            'LocationMasterResource' => 'R',
        ],

        'accounts_user' => [
            'SalesInvoiceResource' => 'C',
            'SalesDcrExpenseResource' => 'C',
        ],

        /* ================= AUDITOR ================= */
        'auditor' => [
            '*' => 'R',
        ],
    ],
];
