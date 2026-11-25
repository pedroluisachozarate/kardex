package com.nativephp.mobile.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/** Matches the login palette while the local runtime starts; no black loading page. */
@Composable
fun KardexLaunchScreen() {
    Box(Modifier.fillMaxSize().background(Color(0xFFF7F9FA)), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(18.dp)) {
            Box(Modifier.size(64.dp).background(Color(0xFF008060), RoundedCornerShape(16.dp)), contentAlignment = Alignment.Center) {
                Text("K", color = Color.White, fontSize = 38.sp, fontWeight = FontWeight.Bold)
            }
            Text("Kardex", color = Color(0xFF1F2937), fontSize = 30.sp, fontWeight = FontWeight.Bold)
        }
    }
}
