// tipos/efectivo/efectivo.component.ts

import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, Validators } from '@angular/forms';
import { NgSelectModule } from '@ng-select/ng-select';
import { BaseFormComponent } from '../../shared/base.component';
import { DatosAnticipo } from '../../models/anticipo.model';

@Component({
  selector: 'app-efectivo',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, NgSelectModule],
  templateUrl: './efectivo.component.html',
  styleUrl: './efectivo.component.css'
})
export class EfectivoFormComponent extends BaseFormComponent {

  inicializarFormulario(): void {
    this.formulario = this.fb.group({
      monto: [null, [Validators.required, Validators.min(0.01)]],
      agencia: ['', Validators.required],
      comentario: ['', Validators.required],
      mismo_usuario_recoge: [true],
      usuario_recoge: [''],
      id_orden: [this.idOrden],
      tipo_solicitud: [this.tipoSolicitud]
    });

    // Configurar validación condicional para usuario_recoge
    this.formulario.get('mismo_usuario_recoge')?.valueChanges.subscribe(mismo => {
      const usuarioControl = this.formulario.get('usuario_recoge');
      if (mismo === false) {
        usuarioControl?.setValidators([Validators.required]);
      } else {
        usuarioControl?.clearValidators();
        usuarioControl?.setValue('');
      }
      usuarioControl?.updateValueAndValidity();
    });
  }

  cargarDatosExternos(): void {
    this.obtenerAgencias();
    this.obtenerUsuarios();
  }

  override cargarDatos(datos: DatosAnticipo): void {
    super.cargarDatos(datos);

    this.formulario.patchValue({
      agencia: datos.agencia || '',
      mismo_usuario_recoge: datos.mismo_usuario_recoge !== false,
      usuario_recoge: datos.usuario_recoge || ''
    });
  }

  obtenerDatos(): DatosAnticipo {
    const formData = this.formulario.value;

    return {
      tipo: 'efectivo',
      monto: formData.monto,
      comentario: formData.comentario,
      agencia: formData.agencia,
      mismo_usuario_recoge: formData.mismo_usuario_recoge,
      usuario_recoge: formData.mismo_usuario_recoge ? '' : formData.usuario_recoge,
      id_orden: formData.id_orden || this.idOrden,
      tipo_solicitud: formData.tipo_solicitud || this.tipoSolicitud,
      concepto: formData.comentario,
      solicitante: 'Usuario actual',
      detalle: formData.comentario
    };
  }
}