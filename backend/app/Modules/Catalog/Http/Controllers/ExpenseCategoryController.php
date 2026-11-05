<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\ExpenseCategory;

/** Kategori biaya operasional (C15). */
class ExpenseCategoryController extends SimpleMasterController
{
    protected string $model = ExpenseCategory::class;

    protected string $auditPrefix = 'EXPENSE_CATEGORY';
}
