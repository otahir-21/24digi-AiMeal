# AI Meal Delivery Integration - Deployment Guide

## 🚀 **Deployment Readiness Status**

### ✅ **READY FOR DEPLOYMENT**
Both Laravel and NestJS backends are now ready for deployment with the AI meal delivery integration.

## 📋 **Pre-Deployment Checklist**

### **Laravel Backend (fitness-meal-planner-1)**

#### **1. Database Migration**
```bash
php artisan migrate
```
This will add the delivery tracking fields to the `meal_sessions` table.

#### **2. Environment Configuration**
Update your `.env` file with these new variables:
```env
# NestJS Backend Integration
NESTJS_API_BASE_URL=https://api.24digi.ae
NESTJS_API_KEY=your_secure_api_key_here
NESTJS_WEBHOOK_SECRET=your_webhook_secret_here
NESTJS_API_TIMEOUT=30
NESTJS_API_RETRY_ATTEMPTS=3

# OpenAI Configuration (if not already set)
OPENAI_API_KEY=your_openai_api_key
OPENAI_MODEL=gpt-4
```

#### **3. Composer Dependencies**
Ensure HTTP client is available:
```bash
composer require guzzlehttp/guzzle
```

#### **4. Clear Caches**
```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

### **NestJS Backend (24-digi-api)**

#### **1. Environment Configuration**
Update your `.env` file:
```env
# Laravel Integration
LARAVEL_API_KEY=your_laravel_api_key_here
LARAVEL_WEBHOOK_URL=https://ai-meals.24digi.ae/api/v1/mobile/webhook/delivery-status
LARAVEL_BASE_URL=https://ai-meals.24digi.ae
```

#### **2. Install Dependencies**
```bash
npm install
```

#### **3. Database Schema Update**
The AI meal delivery schema will be automatically created by Mongoose when the first document is saved.

#### **4. Build Application**
```bash
npm run build
```

## 🔧 **New API Endpoints**

### **Laravel Endpoints**
- `POST /api/v1/mobile/meals/{sessionId}/schedule-delivery` - Schedule AI meal deliveries
- `POST /api/v1/mobile/meals/{sessionId}/consumption` - Update meal consumption
- `GET /api/v1/mobile/meals/{sessionId}/delivery-status` - Get delivery status
- `POST /api/v1/mobile/webhook/delivery-status` - Webhook from NestJS

### **NestJS Endpoints**
- `POST /api/ai-meal-delivery/schedule` - Receive delivery schedule from Laravel
- `POST /api/ai-meal-delivery/consumption` - Update consumption
- `GET /api/ai-meal-delivery/status` - Get delivery status
- `GET /api/ai-meal-delivery/user/{profileId}` - Get user's active deliveries

## ⚠️ **Important Notes**

### **Sequential Deployment Required**
1. **Deploy Laravel first** - Test AI meal generation works
2. **Deploy NestJS second** - Test cross-backend communication
3. **Deploy mobile app** - Test end-to-end flow

### **API Key Configuration**
- Generate secure API keys for cross-backend communication
- Configure webhook secrets for secure communication
- Ensure HTTPS is enabled for production

### **Testing Checklist**
- [ ] AI meal generation works (Laravel)
- [ ] Meal plan approval works (Laravel)
- [ ] Delivery scheduling API works (Laravel → NestJS)
- [ ] Consumption tracking works (Mobile → NestJS → Laravel)
- [ ] Webhook communication works (NestJS → Laravel)

## 🔄 **Integration Flow**
1. User approves AI meal plan (Laravel)
2. User schedules delivery (Laravel → NestJS)
3. NestJS creates delivery orders using existing system
4. Restaurant prepares meals (existing workflow)
5. Delivery tracking via existing NestJS system
6. Consumption logged via mobile app (NestJS → Laravel sync)

## 📊 **Monitoring & Logs**
- Monitor Laravel logs for NestJS integration calls
- Check NestJS logs for delivery scheduling and consumption updates
- Watch for webhook delivery failures between systems
- Monitor AI meal delivery completion rates

## 🚨 **Rollback Plan**
If issues occur:
1. Disable delivery scheduling in mobile app
2. Revert to standalone AI meal generation (Laravel only)
3. Fix integration issues before re-enabling
4. Database rollback: `php artisan migrate:rollback` (Laravel)

## ✅ **Ready for Production**
The integration is complete and ready for deployment with proper configuration and testing.